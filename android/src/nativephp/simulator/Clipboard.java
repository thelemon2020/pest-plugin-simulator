package nativephp.simulator;

import android.content.ClipData;
import android.os.IBinder;
import android.os.PersistableBundle;

import java.lang.reflect.Method;

/**
 * Puts text on the device's clipboard from the reader's process, for Keyboard to paste.
 *
 * `adb shell cmd clipboard set-text` would, but the android-35 and android-36 google_apis
 * images have no clipboard shell command. This asks the clipboard service itself, as scrcpy
 * does, as the shell's package.
 *
 * IClipboard.setPrimaryClip has gained arguments over the API levels: (clip, package) up
 * to Android 9, then a user, then an attribution tag before the user (Android 11), then a
 * device after the user (Android 14). So the arguments go by type: the clip, the package
 * for the first String and null for the tag, and 0 for every int, the first user and the
 * default device.
 */
final class Clipboard {
    private static final String PACKAGE = "com.android.shell";

    // SystemUI's ClipboardListener.EXTRA_SUPPRESS_OVERLAY: a clip the shell sets with it, or
    // any clip on an emulator, does not open the clipboard overlay over the app (Android 13+).
    private static final String SUPPRESS_OVERLAY = "com.android.systemui.SUPPRESS_CLIPBOARD_OVERLAY";

    private final Object service;

    private final Method setPrimaryClip;

    private Clipboard(Object service, Method setPrimaryClip) {
        this.service = service;
        this.setPrimaryClip = setPrimaryClip;
    }

    /**
     * The clipboard service, or null when the device has none or its setPrimaryClip takes
     * an argument of a type not listed above.
     */
    static Clipboard connect() {
        try {
            Object binder = Class.forName("android.os.ServiceManager").getMethod("getService", String.class).invoke(null, "clipboard");

            if (binder == null) {
                return null;
            }

            Object service = Class.forName("android.content.IClipboard$Stub").getMethod("asInterface", IBinder.class).invoke(null, binder);

            for (Method method : service.getClass().getMethods()) {
                if (method.getName().equals("setPrimaryClip") && takesKnownArguments(method)) {
                    return new Clipboard(service, method);
                }
            }
        } catch (Exception exception) {
            return null;
        }

        return null;
    }

    private static boolean takesKnownArguments(Method method) {
        for (Class<?> type : method.getParameterTypes()) {
            if (type != ClipData.class && type != String.class && type != int.class) {
                return false;
            }
        }

        return true;
    }

    /**
     * False when the clipboard service refused the text.
     */
    boolean set(String text) {
        ClipData clip = ClipData.newPlainText(null, text);
        PersistableBundle extras = new PersistableBundle();
        extras.putBoolean(SUPPRESS_OVERLAY, true);
        clip.getDescription().setExtras(extras);

        Class<?>[] types = setPrimaryClip.getParameterTypes();
        Object[] arguments = new Object[types.length];
        boolean named = false;

        for (int i = 0; i < types.length; i++) {
            if (types[i] == ClipData.class) {
                arguments[i] = clip;
            } else if (types[i] == String.class) {
                arguments[i] = named ? null : PACKAGE;
                named = true;
            } else {
                arguments[i] = 0;
            }
        }

        try {
            setPrimaryClip.invoke(service, arguments);

            return true;
        } catch (Exception exception) {
            return false;
        }
    }
}
