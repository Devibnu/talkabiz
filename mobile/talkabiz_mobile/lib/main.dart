import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'app/app.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Firebase disabled until firebase_options.dart is generated.
  // Run: firebase login && flutterfire configure
  // Then uncomment the Firebase block below.
  //
  // try {
  //   await Firebase.initializeApp();
  //   FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);
  // } catch (_) {}

  runApp(const ProviderScope(child: TalkabizApp()));
}
