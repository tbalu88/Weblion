import SwiftUI

@main
struct Crazy360App: App {
    @State private var model = AppModel()

    var body: some Scene {
        WindowGroup {
            RootView()
                .environment(model)
                .environment(\.locale, Locale(identifier: "hu_HU"))
                .preferredColorScheme(.dark)
                .tint(Theme.orange)
                .task { await model.start() }
        }
    }
}
