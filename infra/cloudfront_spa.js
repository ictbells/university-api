function handler(event) {
  var request = event.request;
  var uri = request.uri;

  if (uri.length > 1 && uri.charAt(uri.length - 1) === '/') {
    uri = uri.substring(0, uri.length - 1);
    request.uri = uri;
  }

  // Hashed Vite files (/assets/index-*.js) keep their URI. Missing ones must
  // 403/404 from S3 — never as index.html, or browsers reject module scripts.
  if (uri.indexOf('.') === -1) {
    request.uri = '/index.html';
  }

  return request;
}
