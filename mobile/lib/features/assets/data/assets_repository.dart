import '../domain/asset_models.dart';

abstract class AssetsRepository {
  Future<PropertyAsset> fetchAsset(String id);
}
