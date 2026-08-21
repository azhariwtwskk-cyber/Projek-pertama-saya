import 'package:flutter/material.dart';

import '../../core/theme/app_theme.dart';

class SectionHeader extends StatelessWidget {
  const SectionHeader(
      {super.key, required this.title, this.actionLabel, this.onAction});

  final String title;
  final String? actionLabel;
  final VoidCallback? onAction;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            title,
            style: Theme.of(context)
                .textTheme
                .titleMedium
                ?.copyWith(fontWeight: FontWeight.w700),
          ),
          if (actionLabel != null)
            GestureDetector(
              onTap: onAction,
              child: Text(
                actionLabel!,
                style: TextStyle(
                    color: Theme.of(context).colorScheme.primary,
                    fontWeight: FontWeight.w600,
                    fontSize: 13),
              ),
            ),
        ],
      ),
    );
  }
}

class AppSectionCard extends StatelessWidget {
  const AppSectionCard(
      {super.key,
      required this.child,
      this.padding = const EdgeInsets.all(16),
      this.onTap});

  final Widget child;
  final EdgeInsets padding;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final card = Container(
      padding: padding,
      decoration: BoxDecoration(
        color: Theme.of(context).cardTheme.color,
        borderRadius: BorderRadius.circular(20),
        border: Border.all(color: AppColors.border),
        boxShadow: const [
          BoxShadow(
              color: Color(0x0A101828), blurRadius: 12, offset: Offset(0, 4)),
        ],
      ),
      child: child,
    );
    if (onTap == null) return card;
    return InkWell(
        borderRadius: BorderRadius.circular(20), onTap: onTap, child: card);
  }
}
