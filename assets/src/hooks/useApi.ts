import { useCallback } from 'react'

const useApi = () => {
  const getHeaders = useCallback(() => {
    return {
      'Content-Type': 'application/json',
      'X-WP-Nonce': (window as any).wpApiSettings.nonce
    }
  }, [])

  const get = useCallback(async (endpoint: string) => {
    const response = await fetch(`/wp-json/erp/v1${endpoint}`, {
      headers: getHeaders()
    })

    if (!response.ok) {
      throw new Error('API request failed')
    }

    return response.json()
  }, [getHeaders])

  const post = useCallback(async (endpoint: string, data: any) => {
    const response = await fetch(`/wp-json/erp/v1${endpoint}`, {
      method: 'POST',
      headers: getHeaders(),
      body: JSON.stringify(data)
    })

    if (!response.ok) {
      throw new Error('API request failed')
    }

    return response.json()
  }, [getHeaders])

  const put = useCallback(async (endpoint: string, data: any) => {
    const response = await fetch(`/wp-json/erp/v1${endpoint}`, {
      method: 'PUT',
      headers: getHeaders(),
      body: JSON.stringify(data)
    })

    if (!response.ok) {
      throw new Error('API request failed')
    }

    return response.json()
  }, [getHeaders])

  const del = useCallback(async (endpoint: string) => {
    const response = await fetch(`/wp-json/erp/v1${endpoint}`, {
      method: 'DELETE',
      headers: getHeaders()
    })

    if (!response.ok) {
      throw new Error('API request failed')
    }

    return response.json()
  }, [getHeaders])

  return {
    get,
    post,
    put,
    delete: del
  }
}

export default useApi