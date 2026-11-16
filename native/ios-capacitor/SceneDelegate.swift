import UIKit
import WebKit
import Capacitor

private final class PopupBlockingUIDelegate: NSObject, WKUIDelegate {
    weak var forwardedDelegate: WKUIDelegate?

    override func responds(to selector: Selector!) -> Bool {
        super.responds(to: selector) || forwardedDelegate?.responds(to: selector) == true
    }

    override func forwardingTarget(for selector: Selector!) -> Any? {
        guard forwardedDelegate?.responds(to: selector) == true else {
            return super.forwardingTarget(for: selector)
        }
        return forwardedDelegate
    }

    func webView(
        _ webView: WKWebView,
        createWebViewWith configuration: WKWebViewConfiguration,
        for navigationAction: WKNavigationAction,
        windowFeatures: WKWindowFeatures
    ) -> WKWebView? {
        guard navigationAction.sourceFrame.isMainFrame else { return nil }
        return forwardedDelegate?.webView?(
            webView,
            createWebViewWith: configuration,
            for: navigationAction,
            windowFeatures: windowFeatures
        )
    }
}

private final class PopupBlockingBridgeViewController: CAPBridgeViewController {
    private let popupBlockingDelegate = PopupBlockingUIDelegate()

    override func capacitorDidLoad() {
        super.capacitorDidLoad()
        popupBlockingDelegate.forwardedDelegate = webView?.uiDelegate
        webView?.uiDelegate = popupBlockingDelegate
    }
}

class SceneDelegate: UIResponder, UIWindowSceneDelegate {
    var window: UIWindow?

    func scene(_ scene: UIScene, willConnectTo session: UISceneSession, options connectionOptions: UIScene.ConnectionOptions) {
        guard let windowScene = scene as? UIWindowScene else { return }

        window = UIWindow(windowScene: windowScene)
        window?.rootViewController = PopupBlockingBridgeViewController()
        window?.makeKeyAndVisible()

        SceneDelegateProxy.shared.scene(scene, willConnectTo: session, options: connectionOptions)
    }

    func scene(_ scene: UIScene, openURLContexts URLContexts: Set<UIOpenURLContext>) {
        SceneDelegateProxy.shared.scene(scene, openURLContexts: URLContexts)
    }

    func scene(_ scene: UIScene, continue userActivity: NSUserActivity) {
        SceneDelegateProxy.shared.scene(scene, continue: userActivity)
    }
}
