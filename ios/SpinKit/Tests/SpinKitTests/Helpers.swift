import Foundation

func near(_ a: Double, _ b: Double, _ eps: Double = 1e-6) -> Bool {
    abs(a - b) <= eps
}
