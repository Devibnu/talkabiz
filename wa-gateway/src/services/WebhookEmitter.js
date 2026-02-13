/**
 * WebhookEmitter - Send events to Laravel backend
 * 
 * Responsible for notifying Laravel when WhatsApp events occur:
 * - QR ready
 * - Authenticated
 * - Connected
 * - Disconnected
 * - Message received
 */

const axios = require('axios');
const logger = require('../utils/logger');

class WebhookEmitter {
    constructor() {
        this.retryAttempts = 3;
        this.retryDelay = 1000; // 1 second
        this.timeout = 10000; // 10 seconds
    }

    /**
     * Send webhook to Laravel
     */
    async emit(url, payload) {
        if (!url) {
            logger.warn('Webhook URL not configured, skipping emit', { 
                event: payload.event 
            });
            return false;
        }

        const secret = process.env.WEBHOOK_SECRET || '';

        for (let attempt = 1; attempt <= this.retryAttempts; attempt++) {
            try {
                logger.info('Sending webhook', { 
                    url, 
                    event: payload.event, 
                    attempt 
                });

                const response = await axios.post(url, payload, {
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Gateway-Secret': secret,
                        'X-Webhook-Event': payload.event
                    },
                    timeout: this.timeout
                });

                logger.info('Webhook sent successfully', {
                    event: payload.event,
                    status: response.status,
                    responseData: response.data
                });

                return true;

            } catch (error) {
                const isLastAttempt = attempt === this.retryAttempts;
                
                logger.warn(`Webhook failed (attempt ${attempt}/${this.retryAttempts})`, {
                    event: payload.event,
                    error: error.message,
                    status: error.response?.status,
                    data: error.response?.data
                });

                if (isLastAttempt) {
                    logger.error('Webhook failed after all retries', {
                        url,
                        event: payload.event,
                        klienId: payload.klien_id
                    });
                    return false;
                }

                // Wait before retry
                await this.sleep(this.retryDelay * attempt);
            }
        }

        return false;
    }

    /**
     * Sleep helper
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
}

module.exports = WebhookEmitter;
