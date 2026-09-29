import UIKit

/// نمای «اتصال برقرار نیست» با دکمه تلاش دوباره
final class OfflineView: UIView {
    var onRetry: (() -> Void)?

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = Theme.background

        let icon = UIImageView(image: UIImage(systemName: "wifi.exclamationmark"))
        icon.tintColor = Theme.primary
        icon.contentMode = .scaleAspectFit
        icon.heightAnchor.constraint(equalToConstant: 64).isActive = true

        let title = UILabel()
        title.text = "اتصال برقرار نیست"
        title.font = .boldSystemFont(ofSize: 20)
        title.textAlignment = .center

        let body = UILabel()
        body.text = "اتصال اینترنت را بررسی کنید و دوباره تلاش کنید."
        body.textColor = .secondaryLabel
        body.numberOfLines = 0
        body.textAlignment = .center

        var config = UIButton.Configuration.filled()
        config.title = "تلاش دوباره"
        config.baseBackgroundColor = Theme.primary
        config.baseForegroundColor = Theme.onPrimary
        config.cornerStyle = .large
        let button = UIButton(configuration: config, primaryAction: UIAction { [weak self] _ in self?.onRetry?() })

        let stack = UIStackView(arrangedSubviews: [icon, title, body, button])
        stack.axis = .vertical
        stack.spacing = 14
        stack.alignment = .center
        stack.translatesAutoresizingMaskIntoConstraints = false
        addSubview(stack)
        NSLayoutConstraint.activate([
            stack.centerYAnchor.constraint(equalTo: centerYAnchor),
            stack.leadingAnchor.constraint(equalTo: leadingAnchor, constant: 32),
            stack.trailingAnchor.constraint(equalTo: trailingAnchor, constant: -32),
        ])
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }
}
