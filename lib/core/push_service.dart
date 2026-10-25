import 'dart:io';

import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/material.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';

import '../screens/notifications_screen.dart';
import 'api_client.dart';
import 'config.dart';
import 'router.dart';

class PushService {
  PushService._();

  static final FlutterLocalNotificationsPlugin _local =
      FlutterLocalNotificationsPlugin();
  static const AndroidNotificationChannel _channel = AndroidNotificationChannel(
    'ozie_default',
    'Ozie Notifications',
    description: 'Orders, schemes, returns and other updates',
    importance: Importance.high,
  );

  static bool _inited = false;
  static bool _pendingOpen = false;
  static String? _lastSent;

  static String get _platform => Platform.isIOS ? 'ios' : 'android';

  /// App start pe ek baar (main). Listeners lagata hai.
  static Future<void> init() async {
    if (_inited || !AppConfig.firebaseReady) return;
    _inited = true;
    try {
      await _local.initialize(
        const InitializationSettings(
          android: AndroidInitializationSettings('@mipmap/ic_launcher'),
          iOS: DarwinInitializationSettings(),
        ),
        onDidReceiveNotificationResponse: (_) => _openNotifications(),
      );
      await _local
          .resolvePlatformSpecificImplementation<
              AndroidFlutterLocalNotificationsPlugin>()
          ?.createNotificationChannel(_channel);

      // iOS: foreground mein bhi banner dikhe
      await FirebaseMessaging.instance
          .setForegroundNotificationPresentationOptions(
              alert: true, badge: true, sound: true);

      FirebaseMessaging.onMessage.listen(_onForeground);
      FirebaseMessaging.onMessageOpenedApp.listen((_) => _openNotifications());
      FirebaseMessaging.instance.onTokenRefresh.listen((t) {
        if (ApiClient.instance.hasToken) _send(t);
      });

      // App killed thi, notification tap se khuli
      final initial = await FirebaseMessaging.instance.getInitialMessage();
      if (initial != null) _pendingOpen = true;
    } catch (_) {}
  }

  /// Login / me() ke baad. force=true => server ko phir bhejo (user badal sakta hai).
  static Future<void> register({bool force = false}) async {
    if (!AppConfig.firebaseReady) return;
    try {
      await init();
      final perm = await FirebaseMessaging.instance.requestPermission();
      if (perm.authorizationStatus == AuthorizationStatus.denied) return;
      final t = await FirebaseMessaging.instance.getToken();
      if (t == null) return; // iOS: APNs token late aaye to onTokenRefresh sambhal lega
      if (!force && t == _lastSent) return;
      await _send(t);
    } catch (_) {}
  }

  /// Logout se PEHLE call. Server se token hatao + device token reset.
  static Future<void> unregister() async {
    if (!AppConfig.firebaseReady) return;
    try {
      final t = await FirebaseMessaging.instance.getToken();
      if (t != null && ApiClient.instance.hasToken) {
        await ApiClient.instance.post('v1/auth/device-token', body: {
          'token': t,
          'platform': _platform,
          'action': 'delete',
        });
      }
      await FirebaseMessaging.instance.deleteToken();
    } catch (_) {}
    _lastSent = null;
  }

  /// MainShell khulne ke baad call: killed-state tap ko handle karta hai.
  static void openPendingIfAny() {
    if (_pendingOpen) {
      _pendingOpen = false;
      _openNotifications();
    }
  }

  static Future<void> _send(String token) async {
    await ApiClient.instance.post('v1/auth/device-token', body: {
      'token': token,
      'platform': _platform,
    });
    _lastSent = token;
  }

  static void _onForeground(RemoteMessage m) {
    // Android foreground mein system tray nahi dikhata, local dikhao.
    // iOS ko upar wali presentation options handle karti hain.
    final n = m.notification;
    if (n == null || !Platform.isAndroid) return;
    _local.show(
      n.hashCode,
      n.title,
      n.body,
      NotificationDetails(
        android: AndroidNotificationDetails(
          _channel.id,
          _channel.name,
          channelDescription: _channel.description,
          importance: Importance.high,
          priority: Priority.high,
          icon: '@mipmap/ic_launcher',
        ),
      ),
    );
  }

  static void _openNotifications() {
    final nav = navigatorKey.currentState;
    // App abhi boot ho rahi / login nahi => baad mein khulega
    if (nav == null || !ApiClient.instance.hasToken) {
      _pendingOpen = true;
      return;
    }
    nav.push(MaterialPageRoute<void>(
      builder: (_) => const NotificationsScreen(),
    ));
  }
}