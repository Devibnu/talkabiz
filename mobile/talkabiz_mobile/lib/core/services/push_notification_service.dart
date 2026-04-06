import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/auth/presentation/providers/auth_provider.dart';
import '../network/api_client.dart';

/// Top-level handler for background messages (must be a top-level function).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  debugPrint('[FCM] Background message: ${message.messageId}');
}

final pushNotificationServiceProvider = Provider<PushNotificationService>((ref) {
  return PushNotificationService(ref);
});

class PushNotificationService {
  PushNotificationService(this._ref);

  final Ref _ref;
  final FirebaseMessaging _messaging = FirebaseMessaging.instance;

  /// Call once after Firebase.initializeApp() and successful login.
  Future<void> initialize() async {
    // Request permission (iOS will show system dialog)
    final settings = await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    if (settings.authorizationStatus == AuthorizationStatus.denied) {
      debugPrint('[FCM] Notification permission denied');
      return;
    }

    // Get and register token
    final token = await _messaging.getToken();
    if (token != null) {
      await _registerToken(token);
    }

    // Listen for token refresh
    _messaging.onTokenRefresh.listen(_registerToken);

    // Foreground messages
    FirebaseMessaging.onMessage.listen((message) {
      debugPrint('[FCM] Foreground message: ${message.notification?.title}');
      // Could show a local notification or in-app banner here
    });

    // When user taps notification (app in background)
    FirebaseMessaging.onMessageOpenedApp.listen((message) {
      debugPrint('[FCM] Opened from notification: ${message.data}');
      // Could navigate based on message.data
    });
  }

  /// Register FCM token with the backend.
  Future<void> _registerToken(String token) async {
    try {
      final authState = _ref.read(authControllerProvider);
      if (!authState.isAuthenticated) return;

      final dio = _ref.read(dioProvider);
      await dio.post('/mobile/device-token', data: {
        'token': token,
        'platform': defaultTargetPlatform == TargetPlatform.iOS ? 'ios' : 'android',
      });
      debugPrint('[FCM] Token registered');
    } catch (e) {
      debugPrint('[FCM] Failed to register token: $e');
    }
  }
}
