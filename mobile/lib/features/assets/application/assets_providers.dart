import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../../core/config/app_config.dart';
import '../../auth/application/auth_providers.dart';
import '../data/api_assets_repository.dart';
import '../data/assets_repository.dart';
import '../data/mock_assets_repository.dart';
import '../domain/asset_models.dart';

final assetsRepositoryProvider = Provider<AssetsRepository>((ref) {
  if (AppConfig.useMockApi) return MockAssetsRepository();
  return ApiAssetsRepository(ref.watch(apiClientProvider));
});

final assetDetailProvider = FutureProvider.autoDispose.family<PropertyAsset, String>((ref, id) {
  return ref.watch(assetsRepositoryProvider).fetchAsset(id);
});
