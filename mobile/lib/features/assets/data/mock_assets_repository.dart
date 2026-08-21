import '../../../core/api/api_exception.dart';
import '../../../core/api/mock/mock_fixtures.dart';
import '../domain/asset_models.dart';
import 'assets_repository.dart';

class MockAssetsRepository implements AssetsRepository {
  @override
  Future<PropertyAsset> fetchAsset(String id) async {
    await Future.delayed(const Duration(milliseconds: 400));
    return MockFixtures.instance.assets.firstWhere(
      (a) => a.id == id,
      orElse: () => throw const ApiException(
          ApiFailureType.notFound, 'Asset not found for this property.'),
    );
  }
}
