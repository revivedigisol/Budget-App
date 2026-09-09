import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

export function wpApiUrl(path: string): string {
  if (/^https?:\/\//i.test(path)) return path

  const root = window.wpApiSettings?.root
  if (!root) return path

  return `${root.replace(/\/?$/, "/")}${path.replace(/^\/+/, "")}`
}
