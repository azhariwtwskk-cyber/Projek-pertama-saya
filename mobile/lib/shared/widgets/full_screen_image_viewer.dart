import 'dart:io';

import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:photo_view/photo_view.dart';
import 'package:photo_view/photo_view_gallery.dart';

/// Pinch-to-zoom full-screen viewer for before/after evidence photos
/// (section 11/38). Accepts either remote URLs or local file paths so it
/// works the same for a synced photo and one still sitting in the offline
/// outbox.
class FullScreenImageViewer extends StatefulWidget {
  const FullScreenImageViewer({super.key, required this.imageUrls, this.initialIndex = 0, this.captions});

  final List<String> imageUrls;
  final int initialIndex;
  final List<String>? captions;

  static void open(BuildContext context, List<String> urls, {int initialIndex = 0, List<String>? captions}) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => FullScreenImageViewer(imageUrls: urls, initialIndex: initialIndex, captions: captions),
      fullscreenDialog: true,
    ));
  }

  @override
  State<FullScreenImageViewer> createState() => _FullScreenImageViewerState();
}

class _FullScreenImageViewerState extends State<FullScreenImageViewer> {
  late int _index = widget.initialIndex;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.black,
      appBar: AppBar(
        backgroundColor: Colors.black,
        foregroundColor: Colors.white,
        title: Text('${_index + 1} / ${widget.imageUrls.length}'),
      ),
      body: Stack(
        children: [
          PhotoViewGallery.builder(
            itemCount: widget.imageUrls.length,
            pageController: PageController(initialPage: _index),
            onPageChanged: (i) => setState(() => _index = i),
            builder: (context, i) {
              final url = widget.imageUrls[i];
              final isLocal = !url.startsWith('http');
              return PhotoViewGalleryPageOptions(
                imageProvider: isLocal
                    ? FileImage(File(url)) as ImageProvider
                    : CachedNetworkImageProvider(url),
                minScale: PhotoViewComputedScale.contained,
                maxScale: PhotoViewComputedScale.covered * 3,
              );
            },
          ),
          if (widget.captions != null && widget.captions!.length > _index)
            Positioned(
              left: 0,
              right: 0,
              bottom: 0,
              child: Container(
                padding: const EdgeInsets.all(16),
                color: Colors.black54,
                child: Text(widget.captions![_index], style: const TextStyle(color: Colors.white)),
              ),
            ),
        ],
      ),
    );
  }
}
