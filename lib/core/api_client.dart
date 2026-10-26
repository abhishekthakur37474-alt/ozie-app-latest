import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import 'config.dart';

class ApiException implements Exception {
  final String message;
  final int? statusCode;
  final Map<String, dynamic>? errors;

  ApiException(this.message, {this.statusCode, this.errors});

  @override
  String toString() => message;
}

class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  static const String _tokenKey = 'ozie_token';
  final FlutterSecureStorage _storage = const FlutterSecureStorage();
  String? _token;

  /// Called when a logged-in request gets 401 (token expired/revoked).
  void Function()? onUnauthorized;

  final Dio _dio = Dio(
    BaseOptions(
      baseUrl: AppConfig.baseUrl,
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(seconds: 30),
      contentType: Headers.jsonContentType,
      headers: {
        'Accept': 'application/json',
        'User-Agent':
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'X-Requested-With': 'XMLHttpRequest',
      },
    ),
  );

  bool get hasToken => _token != null && _token!.isNotEmpty;

  Future<void> loadToken() async {
    _token = await _storage.read(key: _tokenKey);
  }

  Future<void> saveToken(String token) async {
    _token = token;
    await _storage.write(key: _tokenKey, value: token);
  }

  Future<void> clearToken() async {
    _token = null;
    await _storage.delete(key: _tokenKey);
  }

  /// Appends filters as `&k=v` so they do not wipe `?route=` on the base URL.
  String _withQuery(String path, Map<String, dynamic>? query) {
    if (query == null || query.isEmpty) return path;
    final parts = <String>[];
    query.forEach((k, v) {
      if (v == null) return;
      final s = '$v';
      if (s.isEmpty) return;
      parts.add('${Uri.encodeQueryComponent(k)}=${Uri.encodeQueryComponent(s)}');
    });
    if (parts.isEmpty) return path;
    return '$path&${parts.join('&')}';
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, dynamic>? query,
  }) =>
      _send('GET', _withQuery(path, query));

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? body,
    Map<String, dynamic>? query,
    bool auth = true,
    bool form = false,
  }) =>
      _send('POST', _withQuery(path, query), body: body, auth: auth, form: form);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    bool auth = true,
    bool form = false,
  }) async {
    try {
      final headers = <String, dynamic>{
        if (auth && hasToken) 'Authorization': 'Bearer $_token',
      };
      final res = await _dio.request<dynamic>(
        path,
        data: method == 'POST' ? (body ?? <String, dynamic>{}) : null,
        options: Options(
          method: method,
          contentType: form
              ? Headers.formUrlEncodedContentType
              : Headers.jsonContentType,
          headers: headers,
        ),
      );
      var data = res.data;
      if (data is String && data.isNotEmpty) {
        try {
          data = jsonDecode(data);
        } catch (_) {}
      }
      if (data is Map) {
        final map = Map<String, dynamic>.from(data);
        if (map['success'] == false) {
          throw ApiException(
            (map['message'] as String?) ?? 'Request failed.',
            statusCode: res.statusCode,
            errors: map['errors'] is Map
                ? Map<String, dynamic>.from(map['errors'] as Map)
                : null,
          );
        }
        return map;
      }
      throw ApiException('Unexpected server response.');
    } on DioException catch (e) {
      final code = e.response?.statusCode;
      final data = e.response?.data;

      if (code == 401 && auth && hasToken) {
        await clearToken();
        onUnauthorized?.call();
      }

      if (data is Map && data['message'] is String) {
        final errs = data['errors'];
        throw ApiException(
          data['message'] as String,
          statusCode: code,
          errors: errs is Map<String, dynamic> ? errs : null,
        );
      }

      if (e.type == DioExceptionType.connectionTimeout ||
          e.type == DioExceptionType.sendTimeout ||
          e.type == DioExceptionType.receiveTimeout ||
          e.type == DioExceptionType.connectionError) {
        throw ApiException('Network error. Check your internet connection.');
      }

      throw ApiException(
        'Server error${code != null ? ' ($code)' : ''}. Please try again.',
        statusCode: code,
      );
    }
  }
}
