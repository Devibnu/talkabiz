module.exports = {
    apps: [{
        name: 'wa-gateway',
        script: 'server.js',
        instances: 1,              // Single instance (WhatsApp limitation)
        autorestart: true,
        watch: false,
        max_memory_restart: '500M',
        min_uptime: '10s',
        max_restarts: 10,
        restart_delay: 5000,       // Wait 5s between restarts
        env: {
            NODE_ENV: 'development',
            PORT: 3001
        },
        env_production: {
            NODE_ENV: 'production',
            PORT: 3001
        },
        error_file: './logs/pm2-error.log',
        out_file: './logs/pm2-out.log',
        log_file: './logs/pm2-combined.log',
        time: true
    }]
};
