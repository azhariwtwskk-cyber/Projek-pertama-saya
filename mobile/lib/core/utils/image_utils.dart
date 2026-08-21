import 'dart:io';
import 'dart:typed_data';

import 'package:image/image.dart' as img;
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../config/app_config.dart';

/// Compresses evidence photos before upload (section 13/34): keeps the
/// long edge under [AppConfig.evidenceImageMaxDimension] and re-encodes as
/// JPEG at [AppConfig.evidenceImageQuality], which typically takes a
/// modern phone-camera photo from several MB down to a few hundred KB —
/// important for staff on limited mobile data plans.
class ImageUtils {
  const ImageUtils._();

  static Future<File> compressForUpload(File source) async {
    final bytes = await source.readAsBytes();
    final decoded = img.decodeImage(bytes);
    if (decoded == null) return source;

    final needsResize = decoded.width > AppConfig.evidenceImageMaxDimension ||
        decoded.height > AppConfig.evidenceImageMaxDimension;
    final resized = needsResize
        ? img.copyResize(
            decoded,
            width: decoded.width >= decoded.height
                ? AppConfig.evidenceImageMaxDimension
                : null,
            height: decoded.height > decoded.width
                ? AppConfig.evidenceImageMaxDimension
                : null,
          )
        : decoded;

    final Uint8List encoded =
        img.encodeJpg(resized, quality: AppConfig.evidenceImageQuality);

    final dir = await getTemporaryDirectory();
    final outPath = p.join(
        dir.path, 'evidence_${DateTime.now().millisecondsSinceEpoch}.jpg');
    final outFile = File(outPath);
    await outFile.writeAsBytes(encoded);
    return outFile;
  }
}
