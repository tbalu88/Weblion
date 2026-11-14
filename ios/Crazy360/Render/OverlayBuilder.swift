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

    /// Felirat középre: ha elfér, egy sorban (legalább a szélesség 5%-ának megfelelő betűvel), különben két sorba törve.
    static func drawCaption(_ text: String, in ctx: CGContext, canvas: CGRect, atTop: Bool, color: CaptionColor) {
        guard !text.isEmpty else { return }
        let rgb = color.rgb
        let fill = CGColor(srgbRed: rgb.r, green: rgb.g, blue: rgb.b, alpha: 1)
        let maxWidth = canvas.width * 0.86
        var size = canvas.width * 0.072
        var lines = [text]
        if !fit(lines, size: &size, minSize: canvas.width * 0.05, maxWidth: maxWidth, color: fill) {
            if let halves = splitInTwo(text) {
                lines = [halves.0, halves.1]
                size = canvas.width * 0.062
            }
            _ = fit(lines, size: &size, minSize: canvas.width * 0.03, maxWidth: maxWidth, color: fill)
        }
        let rendered = lines.map { makeLine($0, size: size, color: fill) }
        let step = size * 1.18
        let shadow = color == .black ? CGColor(srgbRed: 1, green: 1, blue: 1, alpha: 0.55) : CGColor(srgbRed: 0, green: 0, blue: 0, alpha: 0.6)
        ctx.saveGState()
        ctx.setShadow(offset: .zero, blur: size * 0.35, color: shadow)
        for (i, line) in rendered.enumerated() {
            let bounds = CTLineGetBoundsWithOptions(line, [])
            let x = canvas.midX - bounds.width / 2 - bounds.minX
            let y = atTop
                ? canvas.maxY - canvas.height * 0.075 - bounds.maxY - CGFloat(i) * step
                : canvas.minY + canvas.height * 0.07 - bounds.minY + CGFloat(rendered.count - 1 - i) * step
            ctx.textPosition = CGPoint(x: x, y: y)
            CTLineDraw(line, ctx)
        }
        ctx.restoreGState()
    }

    /// Csökkenti a betűméretet, amíg a leghosszabb sor elfér; hamis, ha a legkisebb méretnél sem fér el.
    static func fit(_ lines: [String], size: inout CGFloat, minSize: CGFloat, maxWidth: CGFloat, color: CGColor) -> Bool {
        while true {
            let widest = lines.map { CTLineGetBoundsWithOptions(makeLine($0, size: size, color: color), []).width }.max() ?? 0
            if widest <= maxWidth { return true }
            if size * 0.92 < minSize { return false }
            size *= 0.92
        }
    }

    /// Két, közel egyforma hosszú sor szóhatáron (nil, ha egyetlen szó).
    static func splitInTwo(_ text: String) -> (String, String)? {
        let words = text.split(separator: " ", omittingEmptySubsequences: true).map(String.init)
        guard words.count > 1 else { return nil }
        var best = 1
        var bestScore = Int.max
        for i in 1..<words.count {
            let score = max(words[..<i].joined(separator: " ").count, words[i...].joined(separator: " ").count)
            if score < bestScore {
                bestScore = score
                best = i
            }
        }
        return (words[..<best].joined(separator: " "), words[best...].joined(separator: " "))
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
