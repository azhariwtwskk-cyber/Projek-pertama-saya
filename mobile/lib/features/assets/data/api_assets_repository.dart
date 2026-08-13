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
      (dio) => dio.get(ApiEndpoints.asset(id)),
      (data) {
        final json = data as Map<String, dynamic>;
        return PropertyAsset(
          id: json['id'] as String,
          name: json['name'] as String,
          propertyName: json['property_name'] as String? ?? '',
          location: json['location'] as String? ?? '',
          status: json['status'] as String? ?? '',
          lastMaintenanceDate: json['last_maintenance_date'] == null ? null : DateTime.parse(json['last_maintenance_date'] as String),
          nextMaintenanceDate: json['next_maintenance_date'] == null ? null : DateTime.parse(json['next_maintenance_date'] as String),
        );
      },
    );
  }
}
