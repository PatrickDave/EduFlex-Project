package ph.edu.uc.eduflex;

import android.content.Context;
import android.content.SharedPreferences;
import android.net.Uri;
import android.text.TextUtils;

/**
 * Where EduFlex is being served from, remembered between launches.
 *
 * <p>This is a separate class because the address is the one thing about this
 * app that genuinely varies, and getting it wrong is the most likely reason a
 * demo fails. XAMPP runs on a laptop; the phone has to reach that laptop, and
 * how it reaches it depends on what is available in the room:
 *
 * <ul>
 *   <li><b>USB, no network needed.</b> {@code adb reverse tcp:8080 tcp:80}
 *       makes the phone's own localhost:8080 arrive at the laptop's port 80.
 *       Use {@code http://localhost:8080/eduflex-ui/}. This is the one to
 *       rehearse, because it works in a room with no wifi.</li>
 *   <li><b>Same wifi.</b> Use the laptop's LAN address, for example
 *       {@code http://192.168.1.14/eduflex-ui/}. Find it with {@code ipconfig}.
 *       Windows Firewall blocks inbound port 80 by default, so this needs a
 *       rule adding before it will work.</li>
 *   <li><b>Emulator.</b> {@code http://10.0.2.2/eduflex-ui/} is the host
 *       machine as seen from inside the emulator.</li>
 * </ul>
 *
 * <p>The address is editable from the app's menu rather than compiled in, so
 * moving between those three does not mean rebuilding the APK.
 */
final class ServerConfig {

    private static final String PREFS = "eduflex";
    private static final String KEY_BASE_URL = "base_url";

    /** USB bridge, because it is the only one that works with no network. */
    static final String DEFAULT_BASE_URL = "http://localhost:8080/eduflex-ui/";

    private final SharedPreferences prefs;

    ServerConfig(Context context) {
        this.prefs = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    boolean isConfigured() {
        return !TextUtils.isEmpty(prefs.getString(KEY_BASE_URL, null));
    }

    String baseUrl() {
        return prefs.getString(KEY_BASE_URL, DEFAULT_BASE_URL);
    }

    void setBaseUrl(String raw) {
        prefs.edit().putString(KEY_BASE_URL, normalise(raw)).apply();
    }

    /** The landing page. Everything else is reached by navigating from it. */
    String startUrl() {
        return baseUrl() + "index.php";
    }

    /**
     * Accepts what a tired person actually types and returns something a
     * WebView can load: adds the missing scheme, and the trailing slash that
     * startUrl() depends on.
     */
    static String normalise(String raw) {
        String url = raw == null ? "" : raw.trim();
        if (url.isEmpty()) {
            return DEFAULT_BASE_URL;
        }
        if (!url.startsWith("http://") && !url.startsWith("https://")) {
            url = "http://" + url;
        }
        if (!url.endsWith("/")) {
            url = url + "/";
        }
        return url;
    }

    /**
     * Is this a link within EduFlex itself?
     *
     * <p>Compared on host and port rather than on the whole string, because the
     * app navigates to app/dashboard.php and friends and every one of those is
     * still the same server. Anything else, such as a tel: number in the Who to
     * Contact block, belongs to the phone and not to this WebView.
     */
    boolean isInternal(String url) {
        if (url == null) {
            return false;
        }
        Uri target = Uri.parse(url);
        Uri base = Uri.parse(baseUrl());
        if (target.getHost() == null || base.getHost() == null) {
            return false;
        }
        return target.getHost().equalsIgnoreCase(base.getHost())
                && target.getPort() == base.getPort();
    }
}
