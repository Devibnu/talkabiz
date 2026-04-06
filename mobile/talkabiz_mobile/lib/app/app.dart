import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../core/services/deep_link_service.dart';
import '../features/auth/presentation/providers/auth_provider.dart';
import 'router/app_router.dart';
import 'router/route_names.dart';
import 'theme/app_theme.dart';

class TalkabizApp extends ConsumerStatefulWidget {
  const TalkabizApp({super.key});

  @override
  ConsumerState<TalkabizApp> createState() => _TalkabizAppState();
}

class _TalkabizAppState extends ConsumerState<TalkabizApp>
    with WidgetsBindingObserver {
  late final DeepLinkService _deepLinkService;
  bool _waitingForGoogleOAuth = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _deepLinkService = ref.read(deepLinkServiceProvider);

    _deepLinkService.onGoogleAuthToken = (token) async {
      _waitingForGoogleOAuth = false;
      final authController = ref.read(authControllerProvider.notifier);
      final success = await authController.handleGoogleCallback(token);
      if (success && mounted) {
        ref.read(appRouterProvider).go('/dashboard');
      }
    };

    _deepLinkService.onGoogleAuthError = (error) {
      _waitingForGoogleOAuth = false;
      ref.read(authControllerProvider.notifier).cancelGoogleLogin();
    };

    _deepLinkService.init();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // When app resumes from background after Google OAuth, if no deep link
    // arrived within a short delay, cancel the loading state so the user
    // can interact with the login form again.
    if (state == AppLifecycleState.resumed) {
      Future.delayed(const Duration(seconds: 2), () {
        if (mounted) {
          ref.read(authControllerProvider.notifier).cancelGoogleLogin();
        }
      });
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _deepLinkService.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final router = ref.watch(appRouterProvider);

    return MaterialApp.router(
      title: 'Talkabiz',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      routerConfig: router,
    );
  }
}
