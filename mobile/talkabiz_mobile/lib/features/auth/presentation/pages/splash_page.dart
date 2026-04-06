import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../../../app/router/route_names.dart';
import '../../../../core/services/push_notification_service.dart';
import '../providers/auth_provider.dart';

class SplashPage extends ConsumerStatefulWidget {
  const SplashPage({super.key});

  @override
  ConsumerState<SplashPage> createState() => _SplashPageState();
}

class _SplashPageState extends ConsumerState<SplashPage> {
  @override
  void initState() {
    super.initState();
    Future.microtask(() async {
      await ref.read(authControllerProvider.notifier).restoreSession();
      if (!mounted) return;

      final authState = ref.read(authControllerProvider);
      if (!authState.isAuthenticated) {
        context.goNamed(RouteNames.login);
      } else {
        // Initialize push notifications for authenticated users
        try {
          await ref.read(pushNotificationServiceProvider).initialize();
        } catch (_) {}

        if (authState.session?.user.onboardingComplete != true) {
          context.goNamed(RouteNames.onboarding);
        } else {
          context.goNamed(RouteNames.dashboard);
        }
      }
    });
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(body: Center(child: CircularProgressIndicator()));
  }
}
