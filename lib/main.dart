import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';

import 'firebase_options.dart';
import 'core/api_client.dart';
import 'core/config.dart';
import 'core/push_service.dart';
import 'core/router.dart';
import 'screens/login_screen.dart';
import 'screens/splash_screen.dart';
import 'widgets/app_widgets.dart';

/// Background / killed state mein push aane pe chalta hai. Top-level hona zaroori.
@pragma('vm:entry-point')
Future<void> _firebaseBgHandler(RemoteMessage message) async {
  await Firebase.initializeApp(options: DefaultFirebaseOptions.currentPlatform);
}

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Firebase is needed for Google sign-in and push. App still works without it.
  try {
    await Firebase.initializeApp(
      options: DefaultFirebaseOptions.currentPlatform,
    );
    AppConfig.firebaseReady = true;
    FirebaseMessaging.onBackgroundMessage(_firebaseBgHandler);
    await PushService.init();
  } catch (_) {
    AppConfig.firebaseReady = false;
  }

  // Token expired / revoked -> back to login from anywhere.
  ApiClient.instance.onUnauthorized = () {
    navigatorKey.currentState?.pushAndRemoveUntil(
      MaterialPageRoute<void>(builder: (_) => const LoginScreen()),
      (route) => false,
    );
  };

  runApp(const OzieApp());
}

class OzieApp extends StatelessWidget {
  const OzieApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Ozie',
      debugShowCheckedModeBanner: false,
      navigatorKey: navigatorKey,
      theme: buildTheme(),
      home: const SplashScreen(),
    );
  }
}