# Copyright (C) 2026 wp-native contributors
# SPDX-License-Identifier: MIT

require 'json'

package = JSON.parse(File.read(File.join(__dir__, '..', 'package.json')))

Pod::Spec.new do |s|
  s.name             = 'WPNativeGutenberg'
  s.version          = package['version']
  s.summary          = package['description']
  s.description      = package['description']
  s.license          = package['license']
  s.author           = package['author']
  s.homepage         = package['homepage']
  s.platforms        = { :ios => '17.0' }
  s.source           = { :git => 'https://github.com/Extra-Chill/wp-native.git' }
  s.static_framework = true
  s.swift_version    = '6.0'
  s.source_files     = '**/*.swift'

  s.dependency 'ExpoModulesCore'

  spm_dependency(
    s,
    url: 'https://github.com/wordpress-mobile/GutenbergKit.git',
    requirement: { kind: 'exactVersion', version: '0.19.0' },
    products: ['GutenbergKit']
  )
end
