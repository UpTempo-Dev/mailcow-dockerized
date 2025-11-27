(function () {
  "use strict";

  angular.module("SOGo.Common").config(configure);

  configure.$inject = ["$mdThemingProvider"];

  function configure($mdThemingProvider) {
    // Pick a color pallete: 'indigo', 'teal', 'cyan', 'blue', 'light-freen' etc.
    // https://m1.material.io/style/color.html#color-color-palette
    var predominant_color = "indigo";

    $mdThemingProvider
      .theme("default")
      .primaryPalette(predominant_color, {
        default: "600", // Top toolbar - Professional indigo
        "hue-1": "100", // Secondary elements
        "hue-2": "500", // Sidebar toolbar - Deeper tone
      })
      .accentPalette(predominant_color, {
        default: "100", // FAB buttons - Elegant blue
        "hue-1": "200", // Center list toolbar
        "hue-2": "200", // Selected items - Subtle highlight
      });

    // Optimize performance
    $mdThemingProvider.generateThemesOnDemand(false);
  }
})();
