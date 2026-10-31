/**
 * wp-native — meta package
 *
 * This package is a name placeholder. The actual framework is split
 * across two installable packages:
 *
 *   wp-native-client — universal WordPress client (Abilities API).
 *                      Works in WordPress blocks, React Native, and Node.
 *
 *   wp-native-shell  — React Native app shell built on wp-native-client.
 *                      Drawer, auth, theme, browser handoff, ability-driven
 *                      screens.
 *
 * Pick the one you need. Most consumers want one of:
 *
 *   For Gutenberg blocks / Node scripts:
 *     npm install wp-native-client
 *
 *   For React Native apps (also pulls in the client):
 *     npm install wp-native-shell wp-native-client
 *
 * See https://github.com/Extra-Chill/wp-native for the full framework.
 */

throw new Error(
	'wp-native is a meta package — install wp-native-client and/or ' +
		'wp-native-shell instead. See https://github.com/Extra-Chill/wp-native'
);
