/**
 * Session Routes
 * 
 * POST /api/session/start   - Start new session, get QR
 * GET  /api/session/status  - Check session status  
 * GET  /api/session/qr      - Get current QR code
 * POST /api/session/logout  - Disconnect session
 */

const express = require('express');
const router = express.Router();
const logger = require('../utils/logger');

/**
 * POST /api/session/start
 * Start a new WhatsApp session and get QR code
 * 
 * Body:
 * {
 *   "klien_id": 1,
 *   "session_id": "wa_1_abc123...",
 *   "webhook_url": "http://laravel/api/whatsapp/webhook"  (optional)
 * }
 */
router.post('/start', async (req, res) => {
    const { klien_id, session_id, webhook_url } = req.body;

    if (!klien_id) {
        return res.status(400).json({
            success: false,
            message: 'klien_id is required'
        });
    }

    const sessionManager = req.app.get('sessionManager');

    try {
        const result = await sessionManager.startSession(
            klien_id, 
            session_id || `wa_${klien_id}_${Date.now()}`,
            webhook_url
        );

        res.json({
            success: true,
            ...result
        });

    } catch (error) {
        logger.error('Session start error', { 
            klienId: klien_id, 
            error: error.message 
        });

        res.status(500).json({
            success: false,
            message: error.message || 'Failed to start session'
        });
    }
});

/**
 * GET /api/session/status
 * Check session status
 * 
 * Query: ?klien_id=1
 */
router.get('/status', (req, res) => {
    const { klien_id } = req.query;

    if (!klien_id) {
        return res.status(400).json({
            success: false,
            message: 'klien_id is required'
        });
    }

    const sessionManager = req.app.get('sessionManager');
    const status = sessionManager.getSessionStatus(klien_id);

    res.json({
        success: true,
        ...status
    });
});

/**
 * GET /api/session/qr
 * Get current QR code (if available)
 * 
 * Query: ?klien_id=1
 */
router.get('/qr', (req, res) => {
    const { klien_id } = req.query;

    if (!klien_id) {
        return res.status(400).json({
            success: false,
            message: 'klien_id is required'
        });
    }

    const sessionManager = req.app.get('sessionManager');
    const qr = sessionManager.getQrCode(klien_id);

    if (!qr) {
        return res.status(404).json({
            success: false,
            message: 'QR code not available. Session may be connected or not started.'
        });
    }

    res.json({
        success: true,
        qr: qr
    });
});

/**
 * POST /api/session/logout
 * Disconnect and destroy session
 * 
 * Body:
 * {
 *   "klien_id": 1
 * }
 */
router.post('/logout', async (req, res) => {
    const { klien_id } = req.body;

    if (!klien_id) {
        return res.status(400).json({
            success: false,
            message: 'klien_id is required'
        });
    }

    const sessionManager = req.app.get('sessionManager');

    try {
        const result = await sessionManager.destroySession(klien_id);

        res.json({
            success: true,
            ...result
        });

    } catch (error) {
        logger.error('Session logout error', { 
            klienId: klien_id, 
            error: error.message 
        });

        res.status(500).json({
            success: false,
            message: error.message || 'Failed to logout'
        });
    }
});

/**
 * GET /api/session/list
 * List all active sessions (admin only)
 */
router.get('/list', (req, res) => {
    const sessionManager = req.app.get('sessionManager');
    const sessions = sessionManager.getAllSessions();

    res.json({
        success: true,
        count: sessions.length,
        sessions: sessions
    });
});

module.exports = router;
