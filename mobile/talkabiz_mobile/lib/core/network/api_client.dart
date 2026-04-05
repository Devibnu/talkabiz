import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../storage/secure_storage_service.dart';

const String kApiBaseUrl = 'https://talkabiz.ibnuapps.cloud/api';

final dioProvider = Provider<Dio>((ref) {
  final secureStorage = ref.watch(secureStorageServiceProvider);
  final dio = Dio(
    BaseOptions(
      baseUrl: kApiBaseUrl,
      connectTimeout: const Duration(seconds: 20),
      receiveTimeout: const Duration(seconds: 20),
      headers: {
        'Accept': 'application/json',
      },
    ),
  );

  dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) async {
        final token = await secureStorage.readToken();
        if (token != null && token.isNotEmpty) {
          options.headers['Authorization'] = 'Bearer $token';
        }
        handler.next(options);
      },
    ),
  );

  return dio;
});
