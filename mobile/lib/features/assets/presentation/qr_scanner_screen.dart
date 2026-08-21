import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// Section 23: Asset QR Scanner. The real backend
/// (`staff/asset-inspection/lookup.php?token=`) looks assets up by an
/// opaque `public_token`, and the physical QR code printed by CPMSPro
/// encodes a full portal URL with that token as a query parameter — not
/// a `CPMSPRO:ASSET:<id>` prefix (see
/// mobile/docs/INTEGRATION_REPAIR_REPORT.md).
class QrScannerScreen extends StatefulWidget {
  const QrScannerScreen({super.key});

  @override
  State<QrScannerScreen> createState() => _QrScannerScreenState();
}

class _QrScannerScreenState extends State<QrScannerScreen> {
  final MobileScannerController _controller = MobileScannerController();
  bool _handled = false;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  String _extractAssetId(String raw) {
    final trimmed = raw.trim();
    final uri = Uri.tryParse(trimmed);
    if (uri != null && uri.queryParameters.containsKey('token')) {
      return uri.queryParameters['token']!;
    }
    // Fall back to the raw scanned value in case a label was printed as
    // a bare token rather than a full portal URL.
    return trimmed;
  }

  void _onDetect(BarcodeCapture capture) {
    if (_handled) return;
    final code = capture.barcodes.firstOrNull?.rawValue;
    if (code == null || code.isEmpty) return;
    _handled = true;
    final assetId = _extractAssetId(code);
    context.pushReplacement('/assets/$assetId');
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: const Text('Scan Asset QR'),
        actions: [
          IconButton(
            icon: ValueListenableBuilder(
              valueListenable: _controller,
              builder: (context, state, __) => Icon(
                  state.torchState == TorchState.on
                      ? Icons.flash_on_rounded
                      : Icons.flash_off_rounded),
            ),
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: Stack(
        children: [
          MobileScanner(controller: _controller, onDetect: _onDetect),
          Center(
            child: Container(
              width: 240,
              height: 240,
              decoration: BoxDecoration(
                border: Border.all(color: Colors.white, width: 2),
                borderRadius: BorderRadius.circular(20),
              ),
            ),
          ),
          const Positioned(
            bottom: 40,
            left: 24,
            right: 24,
            child: Text(
              'Align the CPMSPro asset QR code within the frame',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.white70),
            ),
          ),
        ],
      ),
    );
  }
}

extension _FirstOrNull<T> on List<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
