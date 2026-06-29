import plotlyScriptUrl from 'plotly.js-dist-min/plotly.min.js?url';

let plotlyLoaderPromise = null;

export function loadPlotly() {
  if (typeof window === 'undefined') {
    return Promise.resolve(null);
  }

  if (window.Plotly) {
    return Promise.resolve(window.Plotly);
  }

  if (!plotlyLoaderPromise) {
    plotlyLoaderPromise = new Promise((resolve, reject) => {
      const existingScript = document.querySelector('script[data-anbg-plotly-loader="true"]');

      if (existingScript) {
        existingScript.addEventListener('load', () => resolve(window.Plotly || null), { once: true });
        existingScript.addEventListener('error', () => reject(new Error('Chargement Plotly impossible.')), { once: true });
        return;
      }

      const script = document.createElement('script');
      script.src = plotlyScriptUrl;
      script.async = true;
      script.defer = true;
      script.dataset.anbgPlotlyLoader = 'true';
      script.onload = () => resolve(window.Plotly || null);
      script.onerror = () => reject(new Error('Chargement Plotly impossible.'));
      document.head.appendChild(script);
    }).finally(() => {
      if (!window.Plotly) {
        plotlyLoaderPromise = null;
      }
    });
  }

  return plotlyLoaderPromise;
}
