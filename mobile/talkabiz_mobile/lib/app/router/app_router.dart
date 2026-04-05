import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/presentation/pages/login_page.dart';
import '../../features/auth/presentation/pages/splash_page.dart';
import '../../features/contacts/presentation/pages/contacts_page.dart';
import '../../features/dashboard/presentation/pages/dashboard_page.dart';
import '../../features/inbox/presentation/pages/inbox_detail_page.dart';
import '../../features/inbox/presentation/pages/inbox_page.dart';
import 'route_names.dart';

final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    initialLocation: '/',
    routes: [
      GoRoute(
        path: '/',
        name: RouteNames.splash,
        builder: (context, state) => const SplashPage(),
      ),
      GoRoute(
        path: '/login',
        name: RouteNames.login,
        builder: (context, state) => const LoginPage(),
      ),
      GoRoute(
        path: '/dashboard',
        name: RouteNames.dashboard,
        builder: (context, state) => const DashboardPage(),
      ),
      GoRoute(
        path: '/contacts',
        name: RouteNames.contacts,
        builder: (context, state) => const ContactsPage(),
      ),
      GoRoute(
        path: '/inbox',
        name: RouteNames.inbox,
        builder: (context, state) => const InboxPage(),
      ),
      GoRoute(
        path: '/inbox/:conversationId',
        name: RouteNames.inboxDetail,
        builder: (context, state) {
          final conversationId =
              int.tryParse(state.pathParameters['conversationId'] ?? '') ?? 0;

          return InboxDetailPage(conversationId: conversationId);
        },
      ),
    ],
  );
});
