import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../app/router/route_names.dart';

class MobileBottomNav extends StatelessWidget {
  const MobileBottomNav({super.key});

  @override
  Widget build(BuildContext context) {
    final location = GoRouterState.of(context).uri.toString();
    final currentIndex = _resolveIndex(location);

    return NavigationBar(
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
      ],
    );
  }

  int _resolveIndex(String location) {
    if (location.startsWith('/inbox')) {
      return 1;
    }

    if (location.startsWith('/contacts')) {
      return 2;
    }

    return 0;
  }
}
