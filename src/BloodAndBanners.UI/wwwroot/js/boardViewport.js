export function capturePointer(element, pointerId) {
    if (element?.setPointerCapture && !element.hasPointerCapture?.(pointerId)) {
        element.setPointerCapture(pointerId);
    }
}

export function releasePointer(element, pointerId) {
    if (element?.releasePointerCapture && element.hasPointerCapture?.(pointerId)) {
        element.releasePointerCapture(pointerId);
    }
}

export function bounds(element) {
    const rect = element.getBoundingClientRect();
    return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
}
