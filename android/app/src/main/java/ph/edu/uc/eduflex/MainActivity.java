package ph.edu.uc.eduflex;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.text.InputType;
import android.util.Base64;
import android.view.KeyEvent;
import android.view.Menu;
import android.view.MenuItem;
import android.view.View;
import android.view.ViewGroup;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.DownloadListener;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.EditText;
import android.widget.FrameLayout;
import android.widget.ProgressBar;
import android.widget.Toast;

import java.util.ArrayList;
import java.util.List;

/**
 * EduFlex in a WebView.
 *
 * <p>The whole application is the responsive pages Apache already serves. This
 * class exists to supply the five things a bare WebView does not do, each of
 * which breaks a feature the capstone is assessed on:
 *
 * <ol>
 *   <li><b>Cookies that survive the app closing.</b> Otherwise the seven-day
 *       browser trust is lost on every launch and email verification returns.</li>
 *   <li><b>A file chooser.</b> Otherwise Upload Material does nothing at all,
 *       silently, which is the core of the system.</li>
 *   <li><b>A download listener.</b> Otherwise Export my data does nothing, and
 *       that is the withdrawal mechanism Chapter III cites.</li>
 *   <li><b>tel: and mailto: handoff.</b> Otherwise the Who to Contact block is
 *       dead text.</li>
 *   <li><b>Fullscreen video.</b> Otherwise the explainer cannot be expanded.</li>
 * </ol>
 */
public class MainActivity extends Activity {

    private static final int REQ_FILE_CHOOSER = 1001;

    private WebView web;
    private ProgressBar progress;
    private ServerConfig config;

    private ValueCallback<Uri[]> pendingFileCallback;

    /** Set while a <video> is expanded to fullscreen by the page. */
    private View customView;
    private WebChromeClient.CustomViewCallback customViewCallback;
    private FrameLayout fullscreenHost;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        config = new ServerConfig(this);
        web = findViewById(R.id.web);
        progress = findViewById(R.id.progress);
        fullscreenHost = findViewById(R.id.fullscreen_host);

        configureWebView();

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState);
        } else if (config.isConfigured()) {
            web.loadUrl(config.startUrl());
        } else {
            promptForServer(true);
        }
    }

    // ---------------------------------------------------------------- setup

    private void configureWebView() {
        WebSettings s = web.getSettings();

        // jQuery and eduflex.js drive the sidebar drawer, the practice runner
        // and the companion. Without this the app is a set of static pages.
        s.setJavaScriptEnabled(true);

        // localStorage. eduflex.js uses it for per-browser conveniences.
        s.setDomStorageEnabled(true);

        // The pages are already responsive and were verified clean at 320, 360
        // and 390 CSS pixels. Letting the WebView apply its own "wide viewport"
        // guess would override the <meta name="viewport"> the pages ship and
        // render them as a shrunken desktop layout.
        s.setUseWideViewPort(false);
        s.setLoadWithOverviewMode(false);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);

        // The explainer video is preload="none" and plays on a tap, which IS a
        // user gesture, so the default restriction is left in place.
        s.setMediaPlaybackRequiresUserGesture(true);

        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);

        // Session and trust cookies. setAcceptThirdPartyCookies is not needed
        // (everything is same-origin) but costs nothing and avoids a surprise
        // if an iframe is ever added.
        CookieManager cookies = CookieManager.getInstance();
        cookies.setAcceptCookie(true);
        cookies.setAcceptThirdPartyCookies(web, true);

        web.setWebViewClient(new EduFlexWebViewClient());
        web.setWebChromeClient(new EduFlexChromeClient());
        web.setDownloadListener(new EduFlexDownloadListener());
    }

    // ------------------------------------------------------------- lifecycle

    @Override
    protected void onPause() {
        super.onPause();
        // THE important line in this file.
        //
        // The WebView holds cookies in memory and writes them to disk on its
        // own schedule. Android can kill the process at any time after onPause,
        // and anything not flushed is gone. The lost cookie is eduflex_browser,
        // which carries the seven-day trust, so without this flush the learner
        // is asked for an emailed code on every single launch. That is exactly
        // the bug that was fixed in the web app on 2 November; a wrapper
        // without this line reintroduces it on the phone.
        CookieManager.getInstance().flush();
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        if (keyCode == KeyEvent.KEYCODE_BACK) {
            if (customView != null) {
                exitFullscreen();
                return true;
            }
            if (web.canGoBack()) {
                web.goBack();
                return true;
            }
        }
        return super.onKeyDown(keyCode, event);
    }

    // ------------------------------------------------------------------ menu

    @Override
    public boolean onCreateOptionsMenu(Menu menu) {
        getMenuInflater().inflate(R.menu.main, menu);
        return true;
    }

    @Override
    public boolean onOptionsItemSelected(MenuItem item) {
        int id = item.getItemId();
        if (id == R.id.action_reload) {
            web.reload();
            return true;
        }
        if (id == R.id.action_home) {
            web.loadUrl(config.startUrl());
            return true;
        }
        if (id == R.id.action_server) {
            promptForServer(false);
            return true;
        }
        return super.onOptionsItemSelected(item);
    }

    /**
     * Ask for the address EduFlex is served from.
     *
     * @param firstRun true on a fresh install, when there is nothing to go back
     *                 to and the dialog must not be dismissable into a blank
     *                 screen with no way out.
     */
    private void promptForServer(final boolean firstRun) {
        final EditText input = new EditText(this);
        input.setInputType(InputType.TYPE_TEXT_VARIATION_URI);
        input.setHint(ServerConfig.DEFAULT_BASE_URL);
        input.setText(config.baseUrl());
        input.setSelectAllOnFocus(true);

        int pad = (int) (20 * getResources().getDisplayMetrics().density);
        FrameLayout box = new FrameLayout(this);
        box.setPadding(pad, pad / 2, pad, 0);
        box.addView(input);

        AlertDialog dialog = new AlertDialog.Builder(this)
                .setTitle(R.string.server_title)
                .setMessage(R.string.server_help)
                .setView(box)
                .setCancelable(!firstRun)
                .setPositiveButton(R.string.server_connect, (d, which) -> {
                    config.setBaseUrl(input.getText().toString());
                    web.clearHistory();
                    web.loadUrl(config.startUrl());
                })
                .setNegativeButton(firstRun ? R.string.server_use_default
                                            : android.R.string.cancel,
                        (d, which) -> {
                            if (firstRun) {
                                config.setBaseUrl(ServerConfig.DEFAULT_BASE_URL);
                                web.loadUrl(config.startUrl());
                            }
                        })
                .create();
        dialog.getWindow().setSoftInputMode(
                WindowManager.LayoutParams.SOFT_INPUT_STATE_VISIBLE);
        dialog.show();
    }

    // -------------------------------------------------------------- browsing

    private final class EduFlexWebViewClient extends WebViewClient {

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            return handle(request.getUrl());
        }

        private boolean handle(Uri uri) {
            String url = uri.toString();
            if (config.isInternal(url)) {
                return false;   // stay in the WebView
            }
            String scheme = uri.getScheme() == null ? "" : uri.getScheme();
            switch (scheme) {
                case "tel":
                    return startExternal(new Intent(Intent.ACTION_DIAL, uri),
                            R.string.no_dialer);
                case "mailto":
                    return startExternal(new Intent(Intent.ACTION_SENDTO, uri),
                            R.string.no_mail);
                default:
                    // Nothing in EduFlex links off-site by design (no CDNs, no
                    // external fetches), so reaching here means either a typo
                    // in a page or the server address being wrong. Hand it to
                    // the browser rather than showing a blank WebView.
                    return startExternal(new Intent(Intent.ACTION_VIEW, uri),
                            R.string.no_browser);
            }
        }

        private boolean startExternal(Intent intent, int errorRes) {
            try {
                intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
                startActivity(intent);
            } catch (ActivityNotFoundException e) {
                Toast.makeText(MainActivity.this, errorRes, Toast.LENGTH_LONG).show();
            }
            return true;
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request,
                                    WebResourceError error) {
            // Only replace the page for the MAIN document. A failed image or a
            // missing favicon must not blank out a working screen.
            if (!request.isForMainFrame()) {
                return;
            }
            String reason = Build.VERSION.SDK_INT >= Build.VERSION_CODES.M
                    ? String.valueOf(error.getDescription())
                    : "";
            showUnreachable(reason);
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            // Belt and braces: also flush here, so a cookie set by signing in
            // is on disk before the process can be killed.
            CookieManager.getInstance().flush();
        }
    }

    /**
     * A page that says what to check, rather than Chrome's default.
     *
     * <p>The three causes, in the order they actually happen: XAMPP not
     * started, the wrong address, and Windows Firewall silently dropping
     * inbound port 80. Naming them here saves the ten minutes of guessing that
     * a generic "webpage not available" costs.
     */
    private void showUnreachable(String reason) {
        String html = "<!doctype html><html><head><meta charset='utf-8'>"
                + "<meta name='viewport' content='width=device-width,initial-scale=1'>"
                + "<style>"
                + "body{margin:0;padding:28px 20px;font:15px/1.65 system-ui,sans-serif;"
                + "color:#0B2434;background:#F6FBFD;}"
                + "h1{font-size:20px;margin:0 0 6px;}"
                + "p{color:#4A6572;margin:0 0 16px;}"
                + "code{background:#0B2434;color:#CFE9FA;padding:2px 6px;border-radius:5px;"
                + "font-size:13px;word-break:break-all;}"
                + "ol{color:#4A6572;padding-left:20px;} li{margin-bottom:9px;}"
                + "</style></head><body>"
                + "<h1>Cannot reach EduFlex</h1>"
                + "<p>The app is set to <code>" + escape(config.baseUrl()) + "</code></p>"
                + "<ol>"
                + "<li>Is Apache started in the XAMPP Control Panel on the laptop?</li>"
                + "<li>Over USB, has <code>adb reverse tcp:8080 tcp:80</code> been run "
                + "since the cable was last plugged in? It does not survive unplugging.</li>"
                + "<li>Over wifi, is the address the laptop's own "
                + "(<code>ipconfig</code>), and is inbound port 80 allowed through "
                + "Windows Firewall?</li>"
                + "<li>Change the address from the menu, top right.</li>"
                + "</ol>"
                + (reason.isEmpty() ? "" : "<p><small>" + escape(reason) + "</small></p>")
                + "</body></html>";

        // Loaded as a data: URL so the page needs nothing from the server that
        // is, by definition, not answering.
        web.loadData(Base64.encodeToString(html.getBytes(), Base64.NO_PADDING),
                "text/html", "base64");
    }

    private static String escape(String s) {
        return s.replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;");
    }

    // ---------------------------------------------------- uploads and chrome

    private final class EduFlexChromeClient extends WebChromeClient {

        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            progress.setProgress(newProgress);
            progress.setVisibility(newProgress >= 100 ? View.GONE : View.VISIBLE);
        }

        @Override
        public boolean onShowFileChooser(WebView view,
                                         ValueCallback<Uri[]> callback,
                                         FileChooserParams params) {
            if (pendingFileCallback != null) {
                pendingFileCallback.onReceiveValue(null);
            }
            pendingFileCallback = callback;

            Intent pick = new Intent(Intent.ACTION_GET_CONTENT);
            pick.addCategory(Intent.CATEGORY_OPENABLE);
            pick.setType("*/*");

            String[] mimes = toMimeTypes(params.getAcceptTypes());
            if (mimes.length > 0) {
                pick.putExtra(Intent.EXTRA_MIME_TYPES, mimes);
            }

            try {
                startActivityForResult(
                        Intent.createChooser(pick, getString(R.string.pick_file)),
                        REQ_FILE_CHOOSER);
            } catch (ActivityNotFoundException e) {
                pendingFileCallback = null;
                Toast.makeText(MainActivity.this, R.string.no_picker,
                        Toast.LENGTH_LONG).show();
                return false;
            }
            return true;
        }

        @Override
        public void onShowCustomView(View view, CustomViewCallback callback) {
            if (customView != null) {
                callback.onCustomViewHidden();
                return;
            }
            customView = view;
            customViewCallback = callback;
            fullscreenHost.addView(view, new FrameLayout.LayoutParams(
                    ViewGroup.LayoutParams.MATCH_PARENT,
                    ViewGroup.LayoutParams.MATCH_PARENT));
            fullscreenHost.setVisibility(View.VISIBLE);
            web.setVisibility(View.GONE);
            getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        }

        @Override
        public void onHideCustomView() {
            exitFullscreen();
        }
    }

    private void exitFullscreen() {
        if (customView == null) {
            return;
        }
        fullscreenHost.removeView(customView);
        fullscreenHost.setVisibility(View.GONE);
        web.setVisibility(View.VISIBLE);
        customView = null;
        getWindow().clearFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);
        if (customViewCallback != null) {
            customViewCallback.onCustomViewHidden();
            customViewCallback = null;
        }
    }

    /**
     * Turn an HTML {@code accept} attribute into MIME types Android understands.
     *
     * <p>This method is the difference between Upload Material working and
     * showing a file picker in which every document is greyed out.
     *
     * <p>EduFlex writes {@code accept=".pdf,.docx,.txt,.md"} on the material and
     * companion inputs, and real MIME types on the avatar inputs. The WebView
     * passes those strings through verbatim, so {@code getAcceptTypes()} returns
     * a mix of extensions and MIME types. {@code EXTRA_MIME_TYPES} accepts only
     * the latter: hand it ".pdf" and Android matches nothing, with no error.
     *
     * <p>There is no MIME type registered for {@code .md}, so Markdown is mapped
     * to {@code text/plain}, which is what a file manager reports for it anyway.
     */
    private static String[] toMimeTypes(String[] acceptTypes) {
        List<String> out = new ArrayList<>();
        if (acceptTypes == null) {
            return new String[0];
        }
        for (String raw : acceptTypes) {
            if (raw == null) {
                continue;
            }
            for (String piece : raw.split(",")) {
                String t = piece.trim().toLowerCase();
                if (t.isEmpty()) {
                    continue;
                }
                String mime = t.startsWith(".") ? mimeForExtension(t) : t;
                if (mime != null && mime.contains("/") && !out.contains(mime)) {
                    out.add(mime);
                }
            }
        }
        return out.toArray(new String[0]);
    }

    private static String mimeForExtension(String dotted) {
        switch (dotted) {
            case ".pdf":  return "application/pdf";
            case ".docx": return "application/vnd.openxmlformats-officedocument"
                               + ".wordprocessingml.document";
            case ".doc":  return "application/msword";
            case ".txt":  return "text/plain";
            case ".md":   return "text/plain";
            case ".png":  return "image/png";
            case ".jpg":
            case ".jpeg": return "image/jpeg";
            case ".gif":  return "image/gif";
            case ".webp": return "image/webp";
            default:      return null;
        }
    }

    @Override
    protected void onActivityResult(int requestCode, int resultCode, Intent data) {
        if (requestCode != REQ_FILE_CHOOSER) {
            super.onActivityResult(requestCode, resultCode, data);
            return;
        }
        if (pendingFileCallback == null) {
            return;
        }
        Uri[] result = null;
        if (resultCode == RESULT_OK && data != null && data.getData() != null) {
            result = new Uri[]{data.getData()};
        }
        // Passing null on cancel is required, not optional: the page's <input>
        // stays permanently disabled otherwise, and the next tap does nothing.
        pendingFileCallback.onReceiveValue(result);
        pendingFileCallback = null;
    }

    // ------------------------------------------------------------- downloads

    /**
     * Export my data, and the admin export of a participant's data.
     *
     * <p>Both send {@code Content-Disposition: attachment}. A WebView does not
     * download: with no listener the tap does nothing at all. The session
     * cookie has to be copied onto the request by hand, or the download lands
     * on the sign-in page and saves an HTML file named like a JSON one.
     */
    private final class EduFlexDownloadListener implements DownloadListener {
        @Override
        public void onDownloadStart(String url, String userAgent,
                                    String contentDisposition, String mimeType,
                                    long contentLength) {
            try {
                DownloadManager.Request request =
                        new DownloadManager.Request(Uri.parse(url));
                request.addRequestHeader("Cookie",
                        CookieManager.getInstance().getCookie(url));
                request.addRequestHeader("User-Agent", userAgent);

                String name = URLUtil.guessFileName(url, contentDisposition, mimeType);
                request.setTitle(name);
                request.setMimeType(mimeType);
                request.setNotificationVisibility(
                        DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setDestinationInExternalPublicDir(
                        Environment.DIRECTORY_DOWNLOADS, name);

                DownloadManager dm =
                        (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                dm.enqueue(request);
                Toast.makeText(MainActivity.this,
                        getString(R.string.downloading, name),
                        Toast.LENGTH_LONG).show();
            } catch (Exception e) {
                Toast.makeText(MainActivity.this, R.string.download_failed,
                        Toast.LENGTH_LONG).show();
            }
        }
    }
}
