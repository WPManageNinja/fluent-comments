const { rest: restConfig } = window.fluentCommentVars;

const request = (method, route, data = {}) => {
    const url = `${restConfig.url}/${route}`;
    const headers = { 'X-WP-Nonce': restConfig.nonce };

    if (['PUT', 'PATCH', 'DELETE'].includes(method.toUpperCase())) {
        headers['X-HTTP-Method-Override'] = method;
        method = 'POST';
    }

    data.query_timestamp = Date.now();

    const formData = new FormData();
    for (const key in data) {
        formData.append(key, data[key]);
    }

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);

        for (const key in headers) {
            xhr.setRequestHeader(key, headers[key]);
        }

        xhr.responseType = 'json';

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                resolve(xhr.response);
            } else {
                reject({
                    status: xhr.status,
                    statusText: xhr.statusText,
                    response: xhr.response
                });
            }
        };

        xhr.onerror = () => {
            reject({
                status: xhr.status,
                statusText: xhr.statusText
            });
        };

        xhr.send(formData);
    });
};

export const rest = {
    get: (route, data = {}) => request('GET', route, data),
    post: (route, data = {}) => request('POST', route, data),
    put: (route, data = {}) => request('PUT', route, data),
    patch: (route, data = {}) => request('PATCH', route, data),
    delete: (route, data = {}) => request('DELETE', route, data)
};
