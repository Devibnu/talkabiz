/**
 * Message Routes
 * 
 * POST /api/message/send - Send WhatsApp message
 */

const express = require('express');
const router = express.Router();
const logger = require('../utils/logger');

/**
 * POST /api/message/send
 * Send a WhatsApp message
 * 
 * Body:
 * {
 *   "klien_id": 1,
 *   "to": "628123456789",
 *   "message": "Hello World"
 * }
 */
router.post('/send', async (req, res) => {
    const { klien_id, to, message } = req.body;

    if (!klien_id || !to || !message) {
        return res.status(400).json({
            success: false,
            message: 'klien_id, to, and message are required'
        });
    }

    const sessionManager = req.app.get('sessionManager');
    const session = sessionManager.sessions.get(String(klien_id)) || 
                   sessionManager.sessions.get(Number(klien_id));

    if (!session || session.status !== 'connected') {
        return res.status(400).json({
            success: false,
            message: 'WhatsApp not connected. Please scan QR code first.'
        });
    }

    try {
        // Format phone number for WhatsApp
        let phoneNumber = String(to).replace(/[^0-9]/g, '');
        
        // Convert 08xx to 628xx
        if (phoneNumber.startsWith('0')) {
            phoneNumber = '62' + phoneNumber.substring(1);
        }

        // Add @c.us suffix for WhatsApp
        const chatId = phoneNumber + '@c.us';

        // Send message
        const result = await session.client.sendMessage(chatId, message);

        logger.info('Message sent', {
            klienId: klien_id,
            to: phoneNumber,
            messageId: result.id._serialized
        });

        res.json({
            success: true,
            message_id: result.id._serialized,
            timestamp: result.timestamp
        });

    } catch (error) {
        logger.error('Message send failed', {
            klienId: klien_id,
            to,
            error: error.message
        });

        res.status(500).json({
            success: false,
            message: error.message || 'Failed to send message'
        });
    }
});

/**
 * POST /api/message/send-media
 * Send media message (image, document, etc.)
 * 
 * Body:
 * {
 *   "klien_id": 1,
 *   "to": "628123456789",
 *   "media_url": "https://example.com/image.jpg",
 *   "caption": "Check this out!"  (optional)
 * }
 */
router.post('/send-media', async (req, res) => {
    const { klien_id, to, media_url, caption } = req.body;

    if (!klien_id || !to || !media_url) {
        return res.status(400).json({
            success: false,
            message: 'klien_id, to, and media_url are required'
        });
    }

    const sessionManager = req.app.get('sessionManager');
    const session = sessionManager.sessions.get(String(klien_id)) || 
                   sessionManager.sessions.get(Number(klien_id));

    if (!session || session.status !== 'connected') {
        return res.status(400).json({
            success: false,
            message: 'WhatsApp not connected'
        });
    }

    try {
        const { MessageMedia } = require('whatsapp-web.js');
        
        // Download media from URL
        const media = await MessageMedia.fromUrl(media_url);

        // Format phone number
        let phoneNumber = String(to).replace(/[^0-9]/g, '');
        if (phoneNumber.startsWith('0')) {
            phoneNumber = '62' + phoneNumber.substring(1);
        }
        const chatId = phoneNumber + '@c.us';

        // Send media
        const result = await session.client.sendMessage(chatId, media, {
            caption: caption || ''
        });

        logger.info('Media sent', {
            klienId: klien_id,
            to: phoneNumber,
            messageId: result.id._serialized
        });

        res.json({
            success: true,
            message_id: result.id._serialized
        });

    } catch (error) {
        logger.error('Media send failed', {
            klienId: klien_id,
            error: error.message
        });

        res.status(500).json({
            success: false,
            message: error.message || 'Failed to send media'
        });
    }
});

module.exports = router;
