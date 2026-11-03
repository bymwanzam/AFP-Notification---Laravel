/* The app's CSRF token (Laravel's XSRF-TOKEN cookie), sent as the X-CSRFToken header on every call to the app's API. */
function csrfToken() {
  const m = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
  return m ? decodeURIComponent(m[1]) : '';
}
