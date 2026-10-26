import 'package:flutter/material.dart';

/// qty == 0  -> white box with "+"
/// qty  > 0  -> colored pill with  -  qty  +
class ProductQtyButton extends StatelessWidget {
  final int quantity;
  final bool busy;
  final bool canIncrease;
  final VoidCallback onAdd;
  final VoidCallback onMinus;
  final VoidCallback onPlus;
  final Color color;

  const ProductQtyButton({
    super.key,
    required this.quantity,
    required this.onAdd,
    required this.onMinus,
    required this.onPlus,
    this.busy = false,
    this.canIncrease = true,
    this.color = const Color(0xFFE91E63),
  });

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 180),
      transitionBuilder: (child, anim) => ScaleTransition(
        scale: anim,
        child: FadeTransition(opacity: anim, child: child),
      ),
      child: quantity <= 0 ? _addBox() : _stepper(),
    );
  }

  Widget _addBox() {
    return Material(
      key: const ValueKey('add'),
      color: Colors.white,
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(10),
        side: BorderSide(color: color, width: 1.2),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(10),
        onTap: busy ? null : onAdd,
        child: SizedBox(
          width: 36,
          height: 36,
          child: busy
              ? Padding(
                  padding: const EdgeInsets.all(9),
                  child: CircularProgressIndicator(strokeWidth: 2, color: color),
                )
              : Icon(Icons.add, size: 20, color: color),
        ),
      ),
    );
  }

  Widget _stepper() {
    return Material(
      key: const ValueKey('stepper'),
      color: color,
      elevation: 2,
      borderRadius: BorderRadius.circular(10),
      child: SizedBox(
        height: 36,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            _btn(Icons.remove, onMinus),
            SizedBox(
              width: 28,
              child: Center(
                child: busy
                    ? const SizedBox(
                        width: 14,
                        height: 14,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : Text(
                        '$quantity',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
              ),
            ),
            _btn(Icons.add, onPlus, enabled: canIncrease),
          ],
        ),
      ),
    );
  }

  Widget _btn(IconData icon, VoidCallback onTap, {bool enabled = true}) => SizedBox(
        width: 36,
        height: 36,
        child: IconButton(
          padding: EdgeInsets.zero,
          onPressed: (busy || !enabled) ? null : onTap,
          icon: Icon(icon, size: 18, color: enabled ? Colors.white : Colors.white54),
        ),
      );
}