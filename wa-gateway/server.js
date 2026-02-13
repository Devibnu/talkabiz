/**
 * Talkabiz WhatsApp Gateway
 * 
 * Node.js service using whatsapp-web.js for multi-session WhatsApp connections.
 * Each klien (client) gets isolated session with persistent auth.
 * 
 * Endpoints:
 *   POST /api/session/start    - Start new session, get QR code
 *   GET  /api/session/status   - Check session status
 *   POST /api/session/logout   - Disconnect session
 *   POST /api/message/send     - Send message (optional)
 *   GET  /health               - Health check
 */

require('dotenv').config();

const express = require('express');
const cors = require('cors');
const helmet = require('helmet');
const rateLimit = require('express-rate-limit');
const path = require('path');

const logger = require('./src/utils/logger');
const authMiddleware = require('./src/middleware/auth');
const sessionRoutes = require('./src/routes/session');
const messageRoutes = require('./src/routes/message');
const SessionManager = require('./src/services/SessionManager');

const app = express();
const PORT = process.env.PORT || 3001;

// Initialize session manager
const sessionManager = new SessionManager();

// Make session manager available to routes
app.set('sessionManager', sessionManager);

// =============================================================================
// MIDDLEWARE
// =============================================================================

// Security headers
app.use(helmet({
    contentSecurityPolicy: false // Disable for API
}));

// CORS - allow Laravel backend
app.use(cors({
    origin: process.env.LARAVEL_URL || 'http://localhost:8000',
    credentials: true
}));

// Parse JSON
app.use(express.json({ limit: '10mb' }));
app.use(express.urlencoded({ extended: true }));

// Request logging
app.use((req, res, next) => {
    logger.info(`${req.method} ${req.path}`, {
        ip: req.ip,
        userAgent: req.get('user-agent')
    });
    next();
});

// Rate limiting for session endpoints
const sessionLimiter = rateLimit({
    windowMs: 60 * 60 * 1000, // 1 hour
    max: 20, // 20 requests per hour
    message: {
        success: false,
        message: 'Terlalu banyak request. Coba lagi dalam 1 jam.'
    },
    standardHeaders: true,
    legacyHeaders: false
});

// =============================================================================
// ROUTES
// =============================================================================

// Health check (public)
app.get('/health', (req, res) => {
    const sessions = sessionManager.getAllSessions();
    res.json({
        status: 'ok',
        uptime: Math.floor(process.uptime()),
        timestamp: new Date().toISOString(),
        sessions: {
            total: sessions.length,
            connected: sessions.filter(s => s.status === 'connected').length
        },
        memory: {
            used: Math.round(process.memoryUsage().heapUsed / 1024 / 1024) + 'MB',
            total: Math.round(process.memoryUsage().heapTotal / 1024 / 1024) + 'MB'
        }
    });
});

// API routes (protected)
app.use('/api/session', authMiddleware, sessionLimiter, sessionRoutes);
app.use('/api/message', authMiddleware, messageRoutes);

// 404 handler
app.use((req, res) => {
    res.status(404).json({
        success: false,
        message: 'Endpoint tidak ditemukan'
    });
});

// Error handler
app.use((err, req, res, next) => {
    logger.error('Unhandled error:', err);
    res.status(500).json({
        success: false,
        message: process.env.NODE_ENV === 'development' 
            ? err.message 
            : 'Internal server error'
    });
});

// =============================================================================
// GRACEFUL SHUTDOWN
// =============================================================================

const gracefulShutdown = async (signal) => {
    logger.info(`${signal} received. Shutting down gracefully...`);
    
    try {
        // Close all WhatsApp sessions
        await sessionManager.closeAllSessions();
        logger.info('All sessions closed');
        process.exit(0);
    } catch (error) {
        logger.error('Error during shutdown:', error);
        process.exit(1);
    }
};

process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));

// Handle uncaught exceptions
process.on('uncaughtException', (error) => {
    logger.error('Uncaught Exception:', error);
    process.exit(1);
});

process.on('unhandledRejection', (reason, promise) => {
    logger.error('Unhandled Rejection at:', promise, 'reason:', reason);
});

// =============================================================================
// START SERVER
// =============================================================================

app.listen(PORT, () => {
    logger.info(`WhatsApp Gateway running on port ${PORT}`);
    logger.info(`Environment: ${process.env.NODE_ENV || 'development'}`);
    logger.info(`Session storage: ${path.resolve(process.env.SESSION_PATH || './sessions')}`);
    
    // Restore existing sessions on startup
    sessionManager.restoreExistingSessions();
});

module.exports = app;
