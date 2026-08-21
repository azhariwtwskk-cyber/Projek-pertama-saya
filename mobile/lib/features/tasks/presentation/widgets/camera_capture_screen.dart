import 'dart:io';

import 'package:camera/camera.dart';
import 'package:flutter/material.dart';

/// Integrated camera used everywhere staff capture AFTER/completion
/// evidence (section 14): take photo, retake, use photo, flash toggle,
/// jump to gallery instead. Returns the captured [File] via
/// `Navigator.pop`, or null if cancelled.
class CameraCaptureScreen extends StatefulWidget {
  const CameraCaptureScreen({super.key, this.onPickFromGallery});

  final Future<File?> Function()? onPickFromGallery;

  @override
  State<CameraCaptureScreen> createState() => _CameraCaptureScreenState();
}

class _CameraCaptureScreenState extends State<CameraCaptureScreen> {
  CameraController? _controller;
  List<CameraDescription> _cameras = [];
  bool _initializing = true;
  bool _flashOn = false;
  XFile? _captured;
  String? _error;

  @override
  void initState() {
    super.initState();
    _setup();
  }

  Future<void> _setup() async {
    try {
      _cameras = await availableCameras();
      if (_cameras.isEmpty) {
        setState(() {
          _error = 'No camera available on this device.';
          _initializing = false;
        });
        return;
      }
      final controller = CameraController(_cameras.first, ResolutionPreset.high,
          enableAudio: false);
      await controller.initialize();
      if (!mounted) return;
      setState(() {
        _controller = controller;
        _initializing = false;
      });
    } catch (e) {
      setState(() {
        _error = 'Unable to access camera. Please check camera permission.';
        _initializing = false;
      });
    }
  }

  @override
  void dispose() {
    _controller?.dispose();
    super.dispose();
  }

  Future<void> _toggleFlash() async {
    if (_controller == null) return;
    _flashOn = !_flashOn;
    await _controller!.setFlashMode(_flashOn ? FlashMode.torch : FlashMode.off);
    setState(() {});
  }

  Future<void> _takePhoto() async {
    if (_controller == null || !_controller!.value.isInitialized) return;
    final file = await _controller!.takePicture();
    setState(() => _captured = file);
  }

  void _retake() => setState(() => _captured = null);

  void _usePhoto() {
    if (_captured != null) Navigator.of(context).pop(File(_captured!.path));
  }

  Future<void> _pickFromGallery() async {
    final file = await widget.onPickFromGallery?.call();
    if (file != null && mounted) Navigator.of(context).pop(file);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      body: SafeArea(
        child: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_initializing) {
      return const Center(
          child: CircularProgressIndicator(color: Colors.white));
    }
    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.no_photography_outlined,
                  color: Colors.white70, size: 42),
              const SizedBox(height: 12),
              Text(_error!,
                  style: const TextStyle(color: Colors.white70),
                  textAlign: TextAlign.center),
              const SizedBox(height: 16),
              OutlinedButton(
                style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Colors.white38)),
                onPressed: _pickFromGallery,
                child: const Text('Choose from Gallery instead'),
              ),
            ],
          ),
        ),
      );
    }

    if (_captured != null) {
      return Column(
        children: [
          Expanded(
              child: Image.file(File(_captured!.path),
                  fit: BoxFit.contain, width: double.infinity)),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 24, vertical: 20),
            child: Row(
              children: [
                Expanded(
                  child: OutlinedButton.icon(
                    style: OutlinedButton.styleFrom(
                        foregroundColor: Colors.white,
                        side: const BorderSide(color: Colors.white38)),
                    onPressed: _retake,
                    icon: const Icon(Icons.replay_rounded),
                    label: const Text('Retake'),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: ElevatedButton.icon(
                    onPressed: _usePhoto,
                    icon: const Icon(Icons.check_rounded),
                    label: const Text('Use Photo'),
                  ),
                ),
              ],
            ),
          ),
        ],
      );
    }

    return Stack(
      fit: StackFit.expand,
      children: [
        if (_controller != null) CameraPreview(_controller!),
        Positioned(
          top: 8,
          left: 8,
          right: 8,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              IconButton(
                icon: const Icon(Icons.close_rounded, color: Colors.white),
                onPressed: () => Navigator.of(context).pop(),
              ),
              IconButton(
                icon: Icon(
                    _flashOn ? Icons.flash_on_rounded : Icons.flash_off_rounded,
                    color: Colors.white),
                onPressed: _toggleFlash,
              ),
            ],
          ),
        ),
        Positioned(
          bottom: 24,
          left: 0,
          right: 0,
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceEvenly,
            children: [
              IconButton(
                iconSize: 32,
                icon: const Icon(Icons.photo_library_outlined,
                    color: Colors.white),
                onPressed: _pickFromGallery,
              ),
              GestureDetector(
                onTap: _takePhoto,
                child: Container(
                  width: 76,
                  height: 76,
                  decoration: BoxDecoration(
                    shape: BoxShape.circle,
                    border: Border.all(color: Colors.white, width: 4),
                  ),
                  child: Container(
                    margin: const EdgeInsets.all(4),
                    decoration: const BoxDecoration(
                        shape: BoxShape.circle, color: Colors.white),
                  ),
                ),
              ),
              const SizedBox(width: 32),
            ],
          ),
        ),
      ],
    );
  }
}
