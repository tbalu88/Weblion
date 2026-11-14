import SwiftUI

/// Párosítás: a panelben létrehozott gépkulcs beillesztése; mentés előtt ellenőrizzük.
struct KeyView: View {
    @Environment(AppModel.self) private var model
    @Environment(\.dismiss) private var dismiss
    @State private var key = ""
    @State private var checking = false
    @State private var message: String?
    @State private var confirmForget = false

    private var trimmed: String { key.trimmingCharacters(in: .whitespacesAndNewlines) }

    var body: some View {
        Form {
            Section {
                SecureField("Gépkulcs", text: $key)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                PasteButton(payloadType: String.self) { strings in
                    key = strings.first?.trimmingCharacters(in: .whitespacesAndNewlines) ?? ""
                }
                .buttonBorderShape(.capsule)
            } footer: {
                Text("A panelben: Beállítások → Crazy Booth gépek → Új gép. A kulcs csak ezen a telefonon, a Kulcskarikában tárolódik.")
            }
            Section {
                Button {
                    Task { await save() }
                } label: {
                    HStack {
                        Text("Ellenőrzés és mentés")
                        Spacer()
                        if checking { ProgressView() }
                    }
                }
                .disabled(trimmed.count < 20 || checking)
                if let message {
                    Text(message).foregroundStyle(Theme.orange)
                }
            }
            if model.hasKey {
                Section {
                    Button("Gépkulcs törlése", role: .destructive) { confirmForget = true }
                } footer: {
                    Text(model.keyProblem ?? "Ez a telefon párosítva van; a videók a galériába kerülnek.")
                }
            }
        }
        .navigationTitle("Gépkulcs")
        .navigationBarTitleDisplayMode(.inline)
        .confirmationDialog("Törlöd a gépkulcsot erről a telefonról?", isPresented: $confirmForget, titleVisibility: .visible) {
            Button("Törlés", role: .destructive) { model.forgetKey() }
        } message: {
            Text("A feltöltés addig szünetel, amíg új kulcsot nem adsz meg.")
        }
    }

    private func save() async {
        checking = true
        message = nil
        do {
            try await model.pair(key: trimmed)
            checking = false
            dismiss()
        } catch {
            checking = false
            message = error.localizedDescription
        }
    }
}
