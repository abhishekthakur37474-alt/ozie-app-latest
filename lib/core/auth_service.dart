import 'dart:async';

import 'package:firebase_auth/firebase_auth.dart';

import 'api_client.dart';
import 'config.dart';
import 'models.dart';
import 'push_service.dart';

class AuthService {
  AuthService._();

  static final ApiClient _api = ApiClient.instance;
  static const String _deviceName = 'Ozie App';

  static Map<String, dynamic> _dataOf(Map<String, dynamic> res) {
    final data = res['data'];
    if (data is Map<String, dynamic>) return data;
    throw ApiException('Unexpected server response.');
  }

  static Future<Session> _loginResult(Map<String, dynamic> res) async {
    final data = _dataOf(res);
    final token = data['token'];
    if (token is! String || token.isEmpty) {
      throw ApiException('Unexpected server response.');
    }
    await _api.saveToken(token);
    unawaited(PushService.register(force: true));
    return Session.fromData(data);
  }

  static Future<Session> login(String loginId, String password) async {
    final res = await _api.post(
      'v1/auth/login',
      auth: false,
      body: {
        'login_id': loginId,
        'password': password,
        'device_name': _deviceName,
      },
    );
    return _loginResult(res);
  }

  static Future<Session> loginWithGoogle() async {
    if (!AppConfig.firebaseReady) {
      throw ApiException(
        'Google sign-in is not set up yet. Please use email/mobile login.',
      );
    }

    String? idToken;
    try {
      final cred =
          await FirebaseAuth.instance.signInWithProvider(GoogleAuthProvider());
      idToken = await cred.user?.getIdToken();
    } on FirebaseAuthException catch (e) {
      if (e.code == 'canceled' ||
          e.code == 'web-context-canceled' ||
          e.code == 'popup-closed-by-user') {
        throw ApiException('Sign-in cancelled.');
      }
      throw ApiException(e.message ?? 'Google sign-in failed.');
    }

    if (idToken == null) throw ApiException('Google sign-in failed.');

    final res = await _api.post(
      'v1/auth/google',
      auth: false,
      body: {'id_token': idToken, 'device_name': _deviceName},
    );
    return _loginResult(res);
  }

  /// Step 1 of signup: validates + sends OTP to email. Returns server message.
  static Future<String> register({
    required String name,
    required String email,
    required String mobile,
    required String password,
    String verifyMethod = 'email',
  }) async {
    final res = await _api.post(
      'v1/auth/register',
      auth: false,
      body: {
        'name': name,
        'email': email,
        'mobile': mobile,
        'password': password,
        'verify_method': verifyMethod,
      },
    );
    return (res['message'] as String?) ?? 'OTP sent.';
  }

  /// Step 2 of signup: verifies OTP, creates account, logs user in.
  static Future<Session> verifyOtp(String email, String otp) async {
    final res = await _api.post(
      'v1/auth/verify-otp',
      auth: false,
      body: {'email': email, 'otp': otp, 'device_name': _deviceName},
    );
    return _loginResult(res);
  }

  static Future<String> resendOtp(String email) async {
    final res = await _api.post(
      'v1/auth/resend-otp',
      auth: false,
      body: {'email': email},
    );
    return (res['message'] as String?) ?? 'New code sent.';
  }

  static Future<Session> me() async {
    final res = await _api.get('v1/auth/me');
    unawaited(PushService.register());
    return Session.fromData(_dataOf(res));
  }

  static Future<void> logout() async {
    await PushService.unregister(); // token abhi valid hai, pehle hatao
    try {
      await _api.post('v1/auth/logout');
    } on ApiException {
      // ignore: token may already be invalid
    }
    await _api.clearToken();
    if (AppConfig.firebaseReady) {
      try {
        await FirebaseAuth.instance.signOut();
      } catch (_) {}
    }
  }

  /// Returns server message to show the user.
  static Future<String> forgotPassword(String identifier) async {
    final res = await _api.post(
      'v1/auth/forgot-password',
      auth: false,
      body: {'identifier': identifier},
    );
    return (res['message'] as String?) ?? 'Recovery link sent.';
  }

  /// Changes the password of the logged-in user. Other devices are signed out,
  /// the current one stays logged in.
  static Future<void> changePassword({
    required String currentPassword,
    required String newPassword,
  }) async {
    await _api.post('v1/auth/change-password', body: {
      'current_password': currentPassword,
      'new_password': newPassword,
    });
  }
}