import '../../../core/api/api_client.dart';
import '../../../core/api/api_endpoints.dart';
import '../domain/asset_models.dart';
import 'assets_repository.dart';

class ApiAssetsRepository implements AssetsRepository {
  ApiAssetsRepository(this._client);
  final ApiClient _client;

  @override
  Future<PropertyAsset> fetchAsset(String id) {
    return _client.request(
      (dio) =>
          dio.get(ApiEndpoints.assetLookup, queryParameters: {'token': id}),
      (data) {
        final root =
            data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
        final json = root['asset'] is Map
            ? Map<String, dynamic>.from(root['asset'] as Map)
            : root;
        return PropertyAsset(
          id: (json['code'] ?? id).toString(),
          name: (json['name'] ?? '').toString(),
          propertyName: '',
          location: (json['location'] ?? '').toString(),
          status: (json['category'] ?? '').toString(),
          lastMaintenanceDate: null,
          nextMaintenanceDate: null,
        );
      },
    );
  }
}
