import CoreGraphics
import CoreText
import Foundation
import ImageIO
import SpinKit

/// A videóra kerülő réteg: a keret PNG-je (kitöltve) + felirat felül és alul, árnyékkal.
/// Csak CoreGraphics / CoreText – bármelyik szálon futhat.
nonisolated enum OverlayBuilder {
    /// Az átlátszó réteg, vagy nil, ha nincs se keret, se felirat.
    static func make(width: Int, height: Int, frame: URL?, top: String, bottom: String, color: CaptionColor) -> CGImage? {
        let topText = top.trimmingCharacters(in: .whitespacesAndNewlines)
        let bottomText = bottom.trimmingCharacters(in: .whitespacesAndNewlines)
        let frameImage = frame.flatMap(loadImage)
        guard frameImage != nil || !topText.isEmpty || !bottomText.isEmpty else { return nil }
        guard width > 0, height > 0, let space = CGColorSpace(name: CGColorSpace.sRGB),
              let ctx = CGContext(data: nil, width: width, height: height, bitsPerComponent: 8, bytesPerRow: 0, space: space,
                                  bitmapInfo: CGImageAlphaInfo.premultipliedLast.rawValue)
        else { return nil }
        let canvas = CGRect(x: 0, y: 0, width: width, height: height)
        if let img = frameImage {
            ctx.interpolationQuality = .high
            ctx.draw(img, in: aspectFill(CGSize(width: img.width, height: img.height), in: canvas))
        }
        drawCaption(topText, in: ctx, canvas: canvas, atTop: true, color: color)
        drawCaption(bottomText, in: ctx, canvas: canvas, atTop: false, color: color)
        return ctx.makeImage()
    }

    static func loadImage(_ url: URL) -> CGImage? {
        guard let src = CGImageSourceCreateWithURL(url as CFURL, nil) else { return nil }
        return CGImageSourceCreateImageAtIndex(src, 0, nil)
    }

    /// A kép téglalapja „kitöltés” módban (középre, levágással).
    static func aspectFill(_ size: CGSize, in rect: CGRect) -> CGRect {
        guard size.width > 0, size.height > 0 else { return rect }
        let s = max(rect.width / size.width, rect.height / size.height)
        let w = size.width * s, h = size.height * s
        return CGRect(x: rect.midX - w / 2, y: rect.midY - h / 2, width: w, height: h)
    }

    /// Egy sor felirat középre, a szélességhez igazított betűmérettel (a túl hosszú szöveg kisebb lesz).
    static func drawCaption(_ text: String, in ctx: CGContext, canvas: CGRect, atTop: Bool, color: CaptionColor) {
        guard !text.isEmpty else { return }
        let rgb = color.rgb
        let fill = CGColor(srgbRed: rgb.r, green: rgb.g, blue: rgb.b, alpha: 1)
        let maxWidth = canvas.width * 0.86
        var size = canvas.width * 0.072
        var line = makeLine(text, size: size, color: fill)
        var bounds = CTLineGetBoundsWithOptions(line, [])
        while bounds.width > maxWidth && size > canvas.width * 0.03 {
            size *= 0.92
            line = makeLine(text, size: size, color: fill)
            bounds = CTLineGetBoundsWithOptions(line, [])
        }
        let x = canvas.midX - bounds.width / 2 - bounds.minX
        let y = atTop ? canvas.maxY - canvas.height * 0.075 - bounds.maxY : canvas.minY + canvas.height * 0.07 - bounds.minY
        let shadow = color == .black ? CGColor(srgbRed: 1, green: 1, blue: 1, alpha: 0.55) : CGColor(srgbRed: 0, green: 0, blue: 0, alpha: 0.6)
        ctx.saveGState()
        ctx.setShadow(offset: .zero, blur: size * 0.35, color: shadow)
        ctx.textPosition = CGPoint(x: x, y: y)
        CTLineDraw(line, ctx)
        ctx.restoreGState()
    }

    static func makeLine(_ text: String, size: CGFloat, color: CGColor) -> CTLine {
        let font = CTFontCreateUIFontForLanguage(.emphasizedSystem, size, nil) ?? CTFontCreateWithName("Helvetica-Bold" as CFString, size, nil)
        let attrs: [NSAttributedString.Key: Any] = [
            NSAttributedString.Key(kCTFontAttributeName as String): font,
            NSAttributedString.Key(kCTForegroundColorAttributeName as String): color,
        ]
        return CTLineCreateWithAttributedString(NSAttributedString(string: text, attributes: attrs))
    }
}
