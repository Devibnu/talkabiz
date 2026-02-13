# Midtrans Configuration
# Dokumentasi: https://docs.midtrans.com/

# Environment: sandbox / production
MIDTRANS_ENV=sandbox

# Server Key (dari Midtrans Dashboard - Settings > Access Keys)
MIDTRANS_SERVER_KEY=SB-Mid-server-xxxxxxxxxxxx

# Client Key (dari Midtrans Dashboard - Settings > Access Keys)
MIDTRANS_CLIENT_KEY=SB-Mid-client-xxxxxxxxxxxx

# Merchant ID (dari Midtrans Dashboard)
MIDTRANS_MERCHANT_ID=G123456789

# Expiry duration dalam menit (default 60)
MIDTRANS_EXPIRY_DURATION=60

# Notification URL (webhook) - harus HTTPS untuk production
# Default: /api/midtrans/webhook
# MIDTRANS_NOTIFICATION_URL=https://yourdomain.com/api/midtrans/webhook

# Redirect URLs after payment
# MIDTRANS_FINISH_URL=/billing?status=finish
# MIDTRANS_UNFINISH_URL=/billing?status=unfinish
# MIDTRANS_ERROR_URL=/billing?status=error

# ===============================================
# SETUP MIDTRANS SANDBOX
# ===============================================
#
# 1. Register di https://dashboard.sandbox.midtrans.com
# 2. Login dan buka Settings > Access Keys
# 3. Copy Server Key dan Client Key
# 4. Paste ke file .env
#
# ===============================================
# SETUP WEBHOOK DI MIDTRANS DASHBOARD
# ===============================================
#
# 1. Login ke Midtrans Dashboard
# 2. Buka Settings > Payment Notification URL
# 3. Masukkan URL: https://yourdomain.com/api/midtrans/webhook
#    (Harus HTTPS dan accessible dari internet)
#
# Untuk testing lokal, gunakan ngrok:
#    ngrok http 80
#    Lalu set URL: https://xxxx.ngrok.io/api/midtrans/webhook
#
# ===============================================
# TEST PAYMENT (SANDBOX)
# ===============================================
#
# Kartu Kredit Test:
# - Card Number: 4811 1111 1111 1114
# - Expiry: 01/25
# - CVV: 123
# - OTP: 112233
#
# Virtual Account: Gunakan virtual account number yang diberikan
# GoPay/QRIS: Scan QR code dengan Simulator di Midtrans Dashboard
#
