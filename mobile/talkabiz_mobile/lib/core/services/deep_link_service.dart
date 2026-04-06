import 'dart:async';
import 'dart:developer' as developer;

import 'package:app_links/app_links.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final deepLinkServiceProvider = Provider<DeepLinkService>((ref) {
  return DeepLinkService();
});

class DeepLinkService {
  final _appLinks = AppLinks();
  StreamSubscription<Uri>? _sub;

  /// Callback when a Google auth deep link is received.
  void Function(String token)? onGoogleAuthToken;
  void Function(String error)? onGoogleAuthError;

  void init() {
    _sub = _appLinks.uriLinkStream.listen(_handleUri);
    // Also check if app was opened via a deep link (cold start)
    _appLinks.getInitialLink().then((uri) {
      if (uri != null) _handleUri(uri);
    });
  }

  void _handleUri(Uri uri) {
    developer.log('Deep link received: $uri', name: 'DeepLink');

    // Handle: talkabiz://auth/google/callback?status=success&token=xxx
    if (uri.scheme == 'talkabiz' &&
        uri.host == 'auth' &&
        uri.path.contains('google/callback')) {
      final status = uri.queryParameters['status'];
      final token = uri.queryParameters['token'];
      final error = uri.queryParameters['error'];

      if (status == 'success' && token != null && token.isNotEmpty) {
        onGoogleAuthToken?.call(token);
      } else {
        onGoogleAuthError?.call(error ?? 'Login Google gagal.');
      }
    }
  }

  void dispose() {
    _sub?.cancel();
  }
}
