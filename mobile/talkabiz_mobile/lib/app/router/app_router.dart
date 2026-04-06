import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/preview/app_preview.dart';
import '../../features/auth/presentation/pages/login_page.dart';
import '../../features/auth/presentation/pages/splash_page.dart';
import '../../features/billing/presentation/pages/billing_page.dart';
import '../../features/billing/presentation/pages/plans_page.dart';
import '../../features/billing/presentation/pages/topup_page.dart';
import '../../features/billing/presentation/pages/transaction_history_page.dart';
import '../../features/contacts/presentation/pages/contacts_page.dart';
import '../../features/dashboard/presentation/pages/dashboard_page.dart';
import '../../features/inbox/presentation/pages/inbox_detail_page.dart';
import '../../features/inbox/presentation/pages/inbox_page.dart';
import '../../features/onboarding/presentation/pages/onboarding_page.dart';
import '../../features/settings/presentation/pages/profile_page.dart';
import '../../features/settings/presentation/pages/settings_page.dart';
import '../../features/template/presentation/pages/template_detail_page.dart';
import '../../features/template/presentation/pages/template_form_page.dart';
import '../../features/template/presentation/pages/templates_page.dart';
import 'route_names.dart';

final appRouterProvider = Provider<GoRouter>((ref) {
  return GoRouter(
    initialLocation: kPreviewInitialRoute,
    overridePlatformDefaultLocation: kPreviewInitialRoute != '/',
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
      GoRoute(
        path: '/billing',
        name: RouteNames.billing,
        builder: (context, state) => const BillingPage(),
        routes: [
          GoRoute(
            path: 'plans',
            name: RouteNames.billingPlans,
            builder: (context, state) => const PlansPage(),
          ),
          GoRoute(
            path: 'topup',
            name: RouteNames.billingTopUp,
            builder: (context, state) => const TopUpPage(),
          ),
          GoRoute(
            path: 'transactions',
            name: RouteNames.transactionHistory,
            builder: (context, state) => const TransactionHistoryPage(),
          ),
        ],
      ),
      GoRoute(
        path: '/settings',
        name: RouteNames.settings,
        builder: (context, state) => const SettingsPage(),
        routes: [
          GoRoute(
            path: 'profile',
            name: RouteNames.profile,
            builder: (context, state) => const ProfilePage(),
          ),
        ],
      ),
      GoRoute(
        path: '/templates',
        name: RouteNames.templates,
        builder: (context, state) => const TemplatesPage(),
        routes: [
          GoRoute(
            path: 'create',
            name: RouteNames.templateCreate,
            builder: (context, state) => const TemplateFormPage(),
          ),
          GoRoute(
            path: ':id',
            name: RouteNames.templateDetail,
            builder: (context, state) {
              final id =
                  int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
              return TemplateDetailPage(templateId: id);
            },
            routes: [
              GoRoute(
                path: 'edit',
                name: RouteNames.templateEdit,
                builder: (context, state) {
                  final id =
                      int.tryParse(state.pathParameters['id'] ?? '') ?? 0;
                  return TemplateFormPage(templateId: id);
                },
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: '/onboarding',
        name: RouteNames.onboarding,
        builder: (context, state) => const OnboardingPage(),
      ),
    ],
  );
});
