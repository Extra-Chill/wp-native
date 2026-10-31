# wp-native

> Turn any WordPress site into a real native app. Open source. Token auth. No WebViews.

**This is a meta package** — it intentionally has no runtime code. The actual framework lives in two installable packages:

| Package | Purpose | Install when |
|---|---|---|
| **[`wp-native-shell`](https://www.npmjs.com/package/wp-native-shell)** | React Native app shell. Auth gate, theme, drawer content, browser handoff, ability-driven screens. Built for expo-router. | Building a real native iOS/Android app on top of WordPress. |
| **[`wp-native-client`](https://www.npmjs.com/package/wp-native-client)** | Universal WordPress client built on the Abilities API. Discovery + execution. Three transports (fetch, auth-fetch, wp-api-fetch). | Building a Gutenberg block, a React Native app, a Node script — anything that calls a WordPress site's abilities. |

## Quick start

```bash
# React Native app
npm install wp-native-shell wp-native-client

# Gutenberg block or Node script
npm install wp-native-client
```

See the [main repo](https://github.com/Extra-Chill/wp-native) for documentation, contract files, and the roadmap.

## License

MIT
