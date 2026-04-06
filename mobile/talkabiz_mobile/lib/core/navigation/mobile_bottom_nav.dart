import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../app/router/route_names.dart';

const double kMobileBottomNavContentInset = 120;

class MobileBottomNav extends StatelessWidget {
  const MobileBottomNav({super.key});

  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).uri.toString();
    final currentIndex = _resolveIndex(location);

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 0, 16, 14),
        child: ClipRRect(
          borderRadius: BorderRadius.circular(28),
          child: NavigationBar(
            height: 72,
            selectedIndex: currentIndex,
            onDestinationSelected: (index) {
              switch (index) {
                case 0:
                  context.goNamed(RouteNames.dashboard);
                  break;
                case 1:
                  context.goNamed(RouteNames.inbox);
                  break;
                case 2:
                  context.goNamed(RouteNames.contacts);
                  break;
                case 3:
                  context.goNamed(RouteNames.billing);
                  break;
                case 4:
                  context.goNamed(RouteNames.settings);
                  break;
              }
            },
            destinations: const [
              NavigationDestination(
                icon: Icon(Icons.dashboard_outlined),
                selectedIcon: Icon(Icons.dashboard_rounded),
                label: 'Dashboard',
              ),
              NavigationDestination(
                icon: Icon(Icons.chat_bubble_outline_rounded),
                selectedIcon: Icon(Icons.chat_bubble_rounded),
                label: 'Inbox',
              ),
              NavigationDestination(
                icon: Icon(Icons.people_outline_rounded),
                selectedIcon: Icon(Icons.people_rounded),
                label: 'Kontak',
              ),
              NavigationDestination(
                icon: Icon(Icons.receipt_long_outlined),
                selectedIcon: Icon(Icons.receipt_long_rounded),
                label: 'Billing',
              ),
              NavigationDestination(
                icon: Icon(Icons.settings_outlined),
                selectedIcon: Icon(Icons.settings_rounded),
                label: 'Settings',
              ),
            ],
          ),
        ),
      ),
    );
  }

  int _resolveIndex(String location) {
    if (location.startsWith('/inbox')) {
      return 1;
    }

    if (location.startsWith('/contacts')) {
      return 2;
    }

    if (location.startsWith('/billing')) {
      return 3;
    }

    if (location.startsWith('/settings')) {
      return 4;
    }

    return 0;
  }
}
