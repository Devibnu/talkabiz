/**
 * API Key Authentication Middleware
 */

const logger = require('../utils/logger');

const authMiddleware = (req, res, next) => {
    const apiKey = req.headers['x-api-key'];
    const expectedKey = process.env.API_KEY;

    if (!expectedKey) {
        logger.error('API_KEY not configured in environment');
        return res.status(500).json({
            success: false,
            message: 'Server configuration error'
        });
    }

    if (!apiKey) {
        logger.warn('Request without API key', { 
            path: req.path, 
            ip: req.ip 
        });
        return res.status(401).json({
            success: false,
            message: 'API key required'
        });
    }

    if (apiKey !== expectedKey) {
        logger.warn('Invalid API key attempt', { 
            path: req.path, 
            ip: req.ip 
        });
        return res.status(403).json({
            success: false,
            message: 'Invalid API key'
        });
    }

    next();
};

module.exports = authMiddleware;
