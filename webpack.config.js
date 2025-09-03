const defaultConfig = require("@wordpress/scripts/config/webpack.config");

module.exports = {
	...defaultConfig,
	entry: {
		...defaultConfig.entry(),
		"admin-page": "./src/admin-page.jsx",
	},
};
