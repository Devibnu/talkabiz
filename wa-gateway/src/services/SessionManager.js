/**
 * SessionManager - Multi-Session WhatsApp Handler
 * 
 * Manages multiple WhatsApp sessions (one per klien_id).
 * Uses whatsapp-web.js with LocalAuth for persistent sessions.
 */

const { Client, LocalAuth } = require('whatsapp-web.js');
const path = require('path');
const fs = require('fs');
const QRCode = require('qrcode');

const logger = require('../utils/logger');
const WebhookEmitter = require('./WebhookEmitter');

class SessionManager {
    constructor() {
        // Map: klien_id => { client, status, qr, phone, ... }
        this.sessions = new Map();
        
        // Session storage path
        this.sessionPath = path.resolve(process.env.SESSION_PATH || './sessions');
        
        // Ensure session directory exists
        if (!fs.existsSync(this.sessionPath)) {
            fs.mkdirSync(this.sessionPath, { recursive: true });
        }

        // Webhook emitter for Laravel notifications
        this.webhookEmitter = new WebhookEmitter();

        logger.info('SessionManager initialized', { 
            sessionPath: this.sessionPath 
        });
    }

    /**
     * Start a new WhatsApp session for klien
     * Returns QR code as base64 data URI
     */
    async startSession(klienId, sessionId, webhookUrl = null) {
        logger.info('Starting session', { klienId, sessionId });

        // Check if session already exists and is connected
        const existing = this.sessions.get(klienId);
        if (existing && existing.status === 'connected') {
            logger.info('Session already connected', { klienId });
            return {
                success: true,
                status: 'connected',
                phone: existing.phone,
                message: 'Already connected'
            };
        }

        // If existing session is in qr_ready state, destroy it first
        if (existing) {
            await this.destroySession(klienId);
        }

        return new Promise((resolve, reject) => {
            const timeout = setTimeout(() => {
                logger.error('Session start timeout', { klienId });
                this.destroySession(klienId);
                reject(new Error('Timeout waiting for QR code'));
            }, 30000); // 30 second timeout

            try {
                // Create WhatsApp client with LocalAuth for persistence
                const client = new Client({
                    authStrategy: new LocalAuth({
                        clientId: String(klienId),
                        dataPath: this.sessionPath
                    }),
                    puppeteer: {
                        headless: process.env.PUPPETEER_HEADLESS !== 'false',
                        args: [
                            '--no-sandbox',
                            '--disable-setuid-sandbox',
                            '--disable-dev-shm-usage',
                            '--disable-accelerated-2d-canvas',
                            '--no-first-run',
                            '--no-zygote',
                            '--disable-gpu'
                        ],
                        executablePath: process.env.CHROME_PATH || undefined
                    },
                    qrMaxRetries: 3
                });

                // Initialize session state
                const sessionData = {
                    client,
                    klienId,
                    sessionId,
                    webhookUrl: webhookUrl || process.env.LARAVEL_WEBHOOK_URL,
                    status: 'initializing',
                    qr: null,
                    phone: null,
                    createdAt: new Date()
                };
                this.sessions.set(klienId, sessionData);

                // =================================================================
                // EVENT: QR Code Generated
                // =================================================================
                client.on('qr', async (qr) => {
                    clearTimeout(timeout);
                    logger.info('QR code generated', { klienId });

                    try {
                        // Convert QR string to base64 data URI
                        const qrDataUri = await QRCode.toDataURL(qr, {
                            width: 280,
                            margin: 2,
                            color: {
                                dark: '#25D366', // WhatsApp green
                                light: '#FFFFFF'
                            }
                        });

                        sessionData.status = 'qr_ready';
                        sessionData.qr = qrDataUri;
                        sessionData.qrGeneratedAt = new Date();

                        resolve({
                            success: true,
                            status: 'qr_ready',
                            qr: qrDataUri,
                            session_id: sessionId,
                            expires_in: 120 // QR valid for ~120 seconds
                        });

                        // Notify Laravel about QR ready
                        this.webhookEmitter.emit(sessionData.webhookUrl, {
                            event: 'qr.ready',
                            klien_id: klienId,
                            session_id: sessionId,
                            status: 'qr_ready'
                        });

                    } catch (qrError) {
                        logger.error('QR generation failed', { klienId, error: qrError.message });
                        reject(qrError);
                    }
                });

                // =================================================================
                // EVENT: Authenticated (after scan, before ready)
                // =================================================================
                client.on('authenticated', () => {
                    logger.info('Session authenticated', { klienId });
                    sessionData.status = 'authenticated';
                    sessionData.qr = null; // Clear QR after successful auth

                    this.webhookEmitter.emit(sessionData.webhookUrl, {
                        event: 'authenticated',
                        klien_id: klienId,
                        session_id: sessionId,
                        status: 'authenticated'
                    });
                });

                // =================================================================
                // EVENT: Ready (fully connected)
                // =================================================================
                client.on('ready', async () => {
                    logger.info('Session ready/connected', { klienId });
                    
                    // Get phone number info
                    const info = client.info;
                    const phoneNumber = info?.wid?.user || null;

                    sessionData.status = 'connected';
                    sessionData.phone = phoneNumber;
                    sessionData.connectedAt = new Date();

                    // =========================================================
                    // CRITICAL: Notify Laravel that connection is complete
                    // This is what updates wa_terhubung in database!
                    // =========================================================
                    await this.webhookEmitter.emit(sessionData.webhookUrl, {
                        event: 'connection.update',
                        klien_id: klienId,
                        session_id: sessionId,
                        status: 'connected',
                        phone_number: phoneNumber,
                        // For Baileys compatibility, we don't have these
                        // Laravel will handle gracefully
                        phone_number_id: `wa_${klienId}_${phoneNumber}`,
                        business_account_id: `ba_${klienId}`,
                        access_token: `session_${klienId}_${Date.now()}`
                    });

                    logger.info('Connection webhook sent to Laravel', { 
                        klienId, 
                        phone: phoneNumber 
                    });
                });

                // =================================================================
                // EVENT: Authentication Failure
                // =================================================================
                client.on('auth_failure', (error) => {
                    logger.error('Authentication failed', { klienId, error });
                    sessionData.status = 'auth_failed';

                    this.webhookEmitter.emit(sessionData.webhookUrl, {
                        event: 'auth.failure',
                        klien_id: klienId,
                        session_id: sessionId,
                        status: 'error',
                        error: error?.message || 'Authentication failed'
                    });

                    this.destroySession(klienId);
                });

                // =================================================================
                // EVENT: Disconnected
                // =================================================================
                client.on('disconnected', async (reason) => {
                    logger.warn('Session disconnected', { klienId, reason });
                    
                    this.webhookEmitter.emit(sessionData.webhookUrl, {
                        event: 'disconnected',
                        klien_id: klienId,
                        session_id: sessionId,
                        status: 'disconnected',
                        reason: reason
                    });

                    // Clean up session
                    await this.destroySession(klienId);
                });

                // =================================================================
                // EVENT: Message received (optional, for inbox feature)
                // =================================================================
                client.on('message', async (message) => {
                    if (sessionData.status !== 'connected') return;

                    // Only forward if it's not from us
                    if (!message.fromMe) {
                        this.webhookEmitter.emit(sessionData.webhookUrl, {
                            event: 'message.received',
                            klien_id: klienId,
                            session_id: sessionId,
                            message: {
                                id: message.id._serialized,
                                from: message.from,
                                body: message.body,
                                timestamp: message.timestamp,
                                type: message.type
                            }
                        });
                    }
                });

                // Initialize the client
                client.initialize();

            } catch (error) {
                clearTimeout(timeout);
                logger.error('Session start failed', { klienId, error: error.message });
                this.destroySession(klienId);
                reject(error);
            }
        });
    }

    /**
     * Get current session status
     */
    getSessionStatus(klienId) {
        const session = this.sessions.get(klienId);
        
        if (!session) {
            return {
                exists: false,
                status: 'not_found',
                connected: false
            };
        }

        return {
            exists: true,
            status: session.status,
            connected: session.status === 'connected',
            phone: session.phone,
            sessionId: session.sessionId,
            createdAt: session.createdAt,
            connectedAt: session.connectedAt
        };
    }

    /**
     * Get QR code for session (if in qr_ready state)
     */
    getQrCode(klienId) {
        const session = this.sessions.get(klienId);
        
        if (!session || session.status !== 'qr_ready') {
            return null;
        }

        return session.qr;
    }

    /**
     * Logout and destroy session
     */
    async destroySession(klienId) {
        const session = this.sessions.get(klienId);
        
        if (!session) {
            return { success: true, message: 'Session not found' };
        }

        logger.info('Destroying session', { klienId });

        try {
            if (session.client) {
                await session.client.logout();
                await session.client.destroy();
            }
        } catch (error) {
            logger.warn('Error during session destroy', { 
                klienId, 
                error: error.message 
            });
        }

        // Remove from map
        this.sessions.delete(klienId);

        // Optionally clean up session files
        const sessionDir = path.join(this.sessionPath, `session-${klienId}`);
        if (fs.existsSync(sessionDir)) {
            try {
                fs.rmSync(sessionDir, { recursive: true, force: true });
                logger.info('Session files cleaned up', { klienId });
            } catch (e) {
                logger.warn('Failed to clean session files', { klienId });
            }
        }

        return { success: true, message: 'Session destroyed' };
    }

    /**
     * Close all sessions gracefully (for shutdown)
     */
    async closeAllSessions() {
        const klienIds = Array.from(this.sessions.keys());
        
        for (const klienId of klienIds) {
            try {
                const session = this.sessions.get(klienId);
                if (session?.client) {
                    await session.client.destroy();
                }
            } catch (e) {
                logger.warn('Error closing session', { klienId });
            }
        }

        this.sessions.clear();
    }

    /**
     * Restore existing sessions from storage on startup
     */
    async restoreExistingSessions() {
        logger.info('Checking for existing sessions to restore...');

        try {
            const sessionDirs = fs.readdirSync(this.sessionPath)
                .filter(dir => dir.startsWith('session-'));

            for (const dir of sessionDirs) {
                const klienId = dir.replace('session-', '');
                
                // Check if session has valid auth data
                const authPath = path.join(this.sessionPath, dir);
                if (fs.existsSync(authPath)) {
                    logger.info('Found existing session, attempting restore', { klienId });
                    
                    // Start session without waiting for QR (will auto-auth)
                    this.startSession(klienId, `restored_${klienId}_${Date.now()}`)
                        .then(result => {
                            if (result.status === 'connected') {
                                logger.info('Session restored successfully', { klienId });
                            }
                        })
                        .catch(err => {
                            logger.warn('Failed to restore session', { 
                                klienId, 
                                error: err.message 
                            });
                        });
                }
            }
        } catch (error) {
            logger.error('Error restoring sessions', { error: error.message });
        }
    }

    /**
     * Get all sessions info (for health check)
     */
    getAllSessions() {
        const sessions = [];
        
        for (const [klienId, session] of this.sessions) {
            sessions.push({
                klienId,
                status: session.status,
                phone: session.phone,
                connectedAt: session.connectedAt
            });
        }

        return sessions;
    }
}

module.exports = SessionManager;
